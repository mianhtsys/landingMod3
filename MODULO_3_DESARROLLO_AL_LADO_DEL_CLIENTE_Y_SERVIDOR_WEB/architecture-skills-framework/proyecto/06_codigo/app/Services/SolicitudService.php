<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\SedeRepository;
use App\Repositories\ServicioRepository;
use App\Repositories\SolicitudRepository;
use App\Repositories\SolicitudTipoRepository;
use RuntimeException;
use Throwable;

final class SolicitudService
{
    public function __construct(
        private readonly SolicitudRepository $repository = new SolicitudRepository(),
        private readonly CorrelativoService $correlativoService = new CorrelativoService(),
        private readonly SedeRepository $sedeRepo = new SedeRepository(),
        private readonly ServicioRepository $servicioRepo = new ServicioRepository(),
        private readonly SolicitudTipoRepository $tipoRepo = new SolicitudTipoRepository(),
    ) {
    }

    /**
     * Radica una solicitud estándar ordinaria (POST /api/v1/solicitudes).
     */
    public function create(array $data, ?string $idempotencyKey = null, string $clientIp = '127.0.0.1'): array
    {
        $data['es_expedita'] = 0;
        return $this->executeStore($data, $idempotencyKey, $clientIp);
    }

    /**
     * Radica una solicitud expedita de soporte vital (POST /api/v1/solicitudes/expedita).
     * Forzada a prioridad asistencial VITAL para respuesta inmediata de guardia.
     */
    public function createExpedita(array $data, ?string $idempotencyKey = null, string $clientIp = '127.0.0.1'): array
    {
        $data['es_expedita'] = 1;
        $data['prioridad_asistencial'] = 'VITAL';
        $data['sede_id'] = (int) ($data['sede_id'] ?? 1);
        $data['servicio_solicitante_id'] = (int) ($data['servicio_solicitante_id'] ?? 1); // UCI Central
        $data['servicio_resolutor_id'] = (int) ($data['servicio_resolutor_id'] ?? 4);     // Biomédica
        $data['tipo_solicitud_id'] = (int) ($data['tipo_solicitud_id'] ?? 1);             // Falla crítica
        $data['usuario_solicitante_id'] = (int) ($data['usuario_solicitante_id'] ?? 1);
        return $this->executeStore($data, $idempotencyKey, $clientIp);
    }

    /**
     * Lógica central transaccional con soporte de Idempotencia y Outbox Pattern.
     */
    private function executeStore(array $data, ?string $idempotencyKey = null, string $clientIp = '127.0.0.1'): array
    {
        // 1. Control de Idempotencia: si la petición ya fue procesada, devolver ticket existente
        if ($idempotencyKey !== null) {
            $existente = $this->repository->findByOutboxIdempotencyKey($idempotencyKey);
            if ($existente !== null) {
                $detail = $this->repository->findDetailById($existente->id);
                if ($detail !== null) {
                    $detail['idempotent'] = true;
                    return $detail;
                }
            }
        }

        // 2. Validación de integridad de catálogos activos
        $sede = $this->sedeRepo->findById($data['sede_id']);
        if ($sede === null || $sede->estado !== 'activo') {
            throw new RuntimeException('Sede hospitalaria no encontrada o inactiva', 404);
        }

        $srvSol = $this->servicioRepo->findById($data['servicio_solicitante_id']);
        if ($srvSol === null || $srvSol->estado !== 'activo') {
            throw new RuntimeException('Servicio solicitante no encontrado o inactivo', 404);
        }

        $srvRes = $this->servicioRepo->findById($data['servicio_resolutor_id']);
        if ($srvRes === null || $srvRes->estado !== 'activo') {
            throw new RuntimeException('Servicio resolutor no encontrado o inactivo', 404);
        }

        $tipo = $this->tipoRepo->findById($data['tipo_solicitud_id']);
        if ($tipo === null || $tipo->estado !== 'activo') {
            throw new RuntimeException('Tipo de solicitud no encontrado o inactivo', 404);
        }

        // 3. Ejecución en transacción ACID aislada
        $pdo = Database::conn();
        $pdo->beginTransaction();

        try {
            // Generación atómica del correlativo inmutable H{sede}-{año}-{secuencial} (RN-001)
            $anio = (int) date('Y');
            $correlativo = $this->correlativoService->generarSiguiente($data['sede_id'], $anio);
            $data['codigo_correlativo'] = $correlativo;

            // Inserción en la tabla central
            $solicitudId = $this->repository->insert($data);

            // Registro inmutable en sol_historial_estados (RN-012/RN-013)
            $motivo = !empty($data['es_expedita'])
                ? 'Radicación expedita de soporte vital (UCI / Quirófano)'
                : 'Radicación estándar de solicitud de mantenimiento hospitalario';

            $this->repository->insertHistorial(
                solicitudId: $solicitudId,
                estadoAnterior: 'Inicio',
                estadoNuevo: 'Registrada',
                usuarioId: $data['usuario_solicitante_id'],
                ipOrigen: $clientIp,
                motivo: $motivo,
                causal: 'RADICACION_INICIAL'
            );

            // Registro en ops_outbox_eventos (Patrón Transactional Outbox)
            $eventoTipo = !empty($data['es_expedita']) ? 'SOLICITUD_EXPEDITA_CREADA' : 'SOLICITUD_CREADA';
            $payload = [
                'id' => $solicitudId,
                'codigo_correlativo' => $correlativo,
                'sede_id' => $data['sede_id'],
                'sede_codigo' => $sede->codigoInstitucional,
                'servicio_solicitante_id' => $data['servicio_solicitante_id'],
                'servicio_solicitante_nombre' => $srvSol->nombre,
                'servicio_resolutor_id' => $data['servicio_resolutor_id'],
                'servicio_resolutor_nombre' => $srvRes->nombre,
                'tipo_solicitud_id' => $data['tipo_solicitud_id'],
                'tipo_solicitud_nombre' => $tipo->nombre,
                'prioridad_asistencial' => $data['prioridad_asistencial'],
                'es_expedita' => !empty($data['es_expedita']) ? 1 : 0,
                'usuario_solicitante_id' => $data['usuario_solicitante_id'],
                'idempotency_key' => $idempotencyKey,
                'timestamp_utc' => gmdate('Y-m-d H:i:s'),
            ];

            $this->repository->insertOutbox($solicitudId, $eventoTipo, $payload);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // 4. Recuperar detalle institucional completo
        $detail = $this->repository->findDetailById($solicitudId);
        if ($detail === null) {
            throw new RuntimeException('Error interno al recuperar orden hospitalaria creada', 500);
        }
        $detail['idempotent'] = false;
        return $detail;
    }

    /**
     * Lista solicitudes con filtros y relaciones.
     */
    public function list(array $filters = []): array
    {
        return $this->repository->findAll($filters);
    }

    /**
     * Obtiene el detalle de una solicitud por su ID.
     */
    public function get(int $id): array
    {
        $detail = $this->repository->findDetailById($id);
        if ($detail === null) {
            throw new RuntimeException('Solicitud hospitalaria no encontrada', 404);
        }
        return $detail;
    }

    /**
     * Obtiene una solicitud por su código correlativo inmutable.
     */
    public function getByCorrelativo(string $codigo): array
    {
        $sol = $this->repository->findByCorrelativo($codigo);
        if ($sol === null) {
            throw new RuntimeException('Solicitud con correlativo indicado no encontrada', 404);
        }
        $detail = $this->repository->findDetailById($sol->id);
        if ($detail === null) {
            throw new RuntimeException('Error al cargar detalle de solicitud', 500);
        }
        return $detail;
    }

    /**
     * Ejecuta una transición de estado controlada por la FSM con Concurrencia Optimista (D-01, RN-005).
     */
    public function cambiarEstado(
        int $id,
        string $nuevoEstado,
        int $versionEsperada,
        ?int $tecnicoAsignadoId,
        ?string $motivo,
        ?string $causal,
        int $usuarioId,
        string $clientIp = '127.0.0.1'
    ): array {
        // 1. Obtener la orden actual
        $solicitud = $this->repository->findById($id);
        if ($solicitud === null) {
            throw new RuntimeException('Solicitud hospitalaria no encontrada', 404);
        }

        $estadoActual = $solicitud->estado;

        // Control de Concurrencia Optimista (D-01): si la versión esperada no coincide con la versión en BD
        if ($solicitud->version !== $versionEsperada) {
            throw new RuntimeException(
                "Conflicto de concurrencia optimista (D-01): La orden hospitalaria fue modificada concurrentemente por otro usuario. Versión esperada: {$versionEsperada}, Versión actual: {$solicitud->version}. Por favor recargue los datos actualizados.",
                409
            );
        }

        // 2. Validar transición según la FSM (RN-005)
        SolicitudStateMachine::assertCanTransition($estadoActual, $nuevoEstado);

        // Si se asigna un técnico, verificar que el usuario exista
        if ($nuevoEstado === 'Asignada' && $tecnicoAsignadoId !== null) {
            $userRepo = new \App\Repositories\UsuarioRepository();
            $tec = $userRepo->findById($tecnicoAsignadoId);
            if ($tec === null || $tec->estado !== 'activo') {
                throw new RuntimeException('El técnico asignado indicado no existe o no está activo', 404);
            }
        }

        // 3. Ejecutar actualización transaccional ACID
        $pdo = Database::conn();
        $pdo->beginTransaction();

        try {
            // Actualización con bloqueo optimista por versión (D-01)
            $updated = $this->repository->updateEstadoConcurrente(
                id: $id,
                versionEsperada: $versionEsperada,
                nuevoEstado: $nuevoEstado,
                tecnicoAsignadoId: $tecnicoAsignadoId
            );

            if (!$updated) {
                // Conflicto de versión (lost update evitado)
                $solActual = $this->repository->findById($id);
                $versionActual = $solActual?->version ?? 'desconocida';
                throw new RuntimeException(
                    "Conflicto de concurrencia optimista (D-01): La orden hospitalaria fue modificada concurrentemente por otro usuario. Versión esperada: {$versionEsperada}, Versión actual: {$versionActual}. Por favor recargue los datos actualizados.",
                    409
                );
            }

            // Registrar en historial inmutable (RN-012/RN-013)
            $this->repository->insertHistorial(
                solicitudId: $id,
                estadoAnterior: $estadoActual,
                estadoNuevo: $nuevoEstado,
                usuarioId: $usuarioId,
                ipOrigen: $clientIp,
                motivo: $motivo,
                causal: $causal
            );

            // Registrar evento en ops_outbox_eventos
            $outboxPayload = [
                'id' => $id,
                'codigo_correlativo' => $solicitud->codigoCorrelativo,
                'estado_anterior' => $estadoActual,
                'estado_nuevo' => $nuevoEstado,
                'version_anterior' => $versionEsperada,
                'version_nueva' => $versionEsperada + 1,
                'tecnico_asignado_id' => $tecnicoAsignadoId,
                'usuario_id' => $usuarioId,
                'motivo' => $motivo,
                'causal' => $causal,
                'timestamp_utc' => gmdate('Y-m-d H:i:s'),
            ];

            $this->repository->insertOutbox($id, 'SOLICITUD_ESTADO_CAMBIADO', $outboxPayload);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // 4. Retornar detalle actualizado
        $detail = $this->repository->findDetailById($id);
        if ($detail === null) {
            throw new RuntimeException('Error interno al recuperar detalle tras transición', 500);
        }
        return $detail;
    }

    /**
     * Obtiene el historial inmutable de transiciones de una solicitud (Audit Trail).
     * @return list<array>
     */
    public function getHistorial(int $id): array
    {
        $this->get($id); // Valida existencia
        return $this->repository->getHistorialBySolicitudId($id);
    }
}
