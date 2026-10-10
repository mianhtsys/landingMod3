<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Solicitud;

final class SolicitudRepository
{
    private const COLUMNS = 'id, codigo_correlativo, sede_id, servicio_solicitante_id, servicio_resolutor_id, tipo_solicitud_id, ubicacion_especifica, prioridad_asistencial, descripcion_falla, estado, es_expedita, usuario_solicitante_id, tecnico_asignado_id, version, fecha_radicacion_utc, fecha_asignacion_utc, fecha_inicio_proceso_utc, fecha_atencion_utc, fecha_cierre_utc, updated_at_utc';

    public function findById(int $id): ?Solicitud
    {
        $q = Database::conn()->prepare('SELECT ' . self::COLUMNS . ' FROM ' . Solicitud::TABLE . ' WHERE id = :id');
        $q->execute(['id' => $id]);
        $row = $q->fetch();
        return $row === false ? null : Solicitud::fromRow($row);
    }

    public function findByCorrelativo(string $codigo): ?Solicitud
    {
        $q = Database::conn()->prepare('SELECT ' . self::COLUMNS . ' FROM ' . Solicitud::TABLE . ' WHERE codigo_correlativo = :codigo');
        $q->execute(['codigo' => trim($codigo)]);
        $row = $q->fetch();
        return $row === false ? null : Solicitud::fromRow($row);
    }

    public function existsCorrelativo(string $codigo): bool
    {
        $q = Database::conn()->prepare('SELECT 1 FROM ' . Solicitud::TABLE . ' WHERE codigo_correlativo = :codigo');
        $q->execute(['codigo' => trim($codigo)]);
        return (bool) $q->fetchColumn();
    }

    /**
     * Inserta una nueva solicitud hospitalaria.
     * @param array $data Datos validados de la solicitud
     * @return int ID de la fila insertada
     */
    public function insert(array $data): int
    {
        $sql = '
            INSERT INTO ' . Solicitud::TABLE . ' (
                codigo_correlativo, sede_id, servicio_solicitante_id, servicio_resolutor_id,
                tipo_solicitud_id, ubicacion_especifica, prioridad_asistencial, descripcion_falla,
                estado, es_expedita, usuario_solicitante_id, version, fecha_radicacion_utc
            ) VALUES (
                :codigo_correlativo, :sede_id, :servicio_solicitante_id, :servicio_resolutor_id,
                :tipo_solicitud_id, :ubicacion_especifica, :prioridad_asistencial, :descripcion_falla,
                :estado, :es_expedita, :usuario_solicitante_id, 1, UTC_TIMESTAMP()
            )
        ';

        $q = Database::conn()->prepare($sql);
        $q->execute([
            'codigo_correlativo' => $data['codigo_correlativo'],
            'sede_id' => $data['sede_id'],
            'servicio_solicitante_id' => $data['servicio_solicitante_id'],
            'servicio_resolutor_id' => $data['servicio_resolutor_id'],
            'tipo_solicitud_id' => $data['tipo_solicitud_id'],
            'ubicacion_especifica' => $data['ubicacion_especifica'],
            'prioridad_asistencial' => $data['prioridad_asistencial'],
            'descripcion_falla' => $data['descripcion_falla'],
            'estado' => $data['estado'] ?? 'Registrada',
            'es_expedita' => !empty($data['es_expedita']) ? 1 : 0,
            'usuario_solicitante_id' => $data['usuario_solicitante_id'],
        ]);

        return (int) Database::conn()->lastInsertId();
    }

    /**
     * Busca una solicitud vinculada a una clave de idempotencia procesada en el Outbox.
     */
    public function findByOutboxIdempotencyKey(string $key): ?Solicitud
    {
        $sql = "
            SELECT s." . str_replace(', ', ', s.', self::COLUMNS) . "
            FROM " . Solicitud::TABLE . " s
            INNER JOIN ops_outbox_eventos o ON o.solicitud_id = s.id
            WHERE JSON_UNQUOTE(JSON_EXTRACT(o.payload_json, '$.idempotency_key')) = :key
            LIMIT 1
        ";
        $q = Database::conn()->prepare($sql);
        $q->execute(['key' => trim($key)]);
        $row = $q->fetch();
        return $row === false ? null : Solicitud::fromRow($row);
    }

    /**
     * Inserta un evento transaccional en ops_outbox_eventos (Patrón Transactional Outbox).
     */
    public function insertOutbox(int $solicitudId, string $tipoEvento, array $payload): int
    {
        $sql = "
            INSERT INTO ops_outbox_eventos (
                solicitud_id, tipo_evento, payload_json, estado, reintentos, created_at_utc
            ) VALUES (
                :solicitud_id, :tipo_evento, :payload_json, 'PENDIENTE', 0, UTC_TIMESTAMP()
            )
        ";
        $q = Database::conn()->prepare($sql);
        $q->execute([
            'solicitud_id' => $solicitudId,
            'tipo_evento' => $tipoEvento,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return (int) Database::conn()->lastInsertId();
    }

    /**
     * Inserta un cambio en la bitácora inmutable sol_historial_estados (RN-012/RN-013).
     */
    public function insertHistorial(
        int $solicitudId,
        string $estadoAnterior,
        string $estadoNuevo,
        int $usuarioId,
        string $ipOrigen,
        ?string $motivo = null,
        ?string $causal = null
    ): int {
        $sql = "
            INSERT INTO sol_historial_estados (
                solicitud_id, estado_anterior, estado_nuevo, causal_codigo,
                motivo_texto, usuario_id, ip_origen, created_at_utc
            ) VALUES (
                :solicitud_id, :estado_anterior, :estado_nuevo, :causal_codigo,
                :motivo_texto, :usuario_id, :ip_origen, UTC_TIMESTAMP()
            )
        ";
        $q = Database::conn()->prepare($sql);
        $q->execute([
            'solicitud_id' => $solicitudId,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $estadoNuevo,
            'causal_codigo' => $causal,
            'motivo_texto' => $motivo,
            'usuario_id' => $usuarioId,
            'ip_origen' => $ipOrigen,
        ]);
        return (int) Database::conn()->lastInsertId();
    }

    /**
     * Lista solicitudes con soporte de filtros hospitalarios y paginación.
     */
    public function findAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['sede_id'])) {
            $where[] = 's.sede_id = :sede_id';
            $params['sede_id'] = (int) $filters['sede_id'];
        }
        if (!empty($filters['estado'])) {
            $where[] = 's.estado = :estado';
            $params['estado'] = (string) $filters['estado'];
        }
        if (!empty($filters['prioridad_asistencial'])) {
            $where[] = 's.prioridad_asistencial = :prioridad';
            $params['prioridad'] = (string) $filters['prioridad_asistencial'];
        }
        if (isset($filters['es_expedita'])) {
            $where[] = 's.es_expedita = :es_expedita';
            $params['es_expedita'] = !empty($filters['es_expedita']) ? 1 : 0;
        }

        $sql = '
            SELECT 
                s.id, s.codigo_correlativo, s.sede_id, se.codigo_institucional AS sede_codigo, se.nombre AS sede_nombre,
                s.servicio_solicitante_id, ssol.nombre AS servicio_solicitante_nombre,
                s.servicio_resolutor_id, sres.nombre AS servicio_resolutor_nombre,
                s.tipo_solicitud_id, t.nombre AS tipo_solicitud_nombre, t.codigo_tipo, t.prioridad_sugerida,
                s.ubicacion_especifica, s.prioridad_asistencial, s.descripcion_falla,
                s.estado, s.es_expedita, s.usuario_solicitante_id, u.nombre AS usuario_solicitante_nombre,
                s.tecnico_asignado_id, utec.nombre AS tecnico_asignado_nombre,
                s.version, s.fecha_radicacion_utc, s.updated_at_utc
            FROM ' . Solicitud::TABLE . ' s
            INNER JOIN org_sedes se ON se.id = s.sede_id
            INNER JOIN org_servicios ssol ON ssol.id = s.servicio_solicitante_id
            INNER JOIN org_servicios sres ON sres.id = s.servicio_resolutor_id
            INNER JOIN cat_solicitud_tipos t ON t.id = s.tipo_solicitud_id
            INNER JOIN auth_usuario u ON u.id = s.usuario_solicitante_id
            LEFT JOIN auth_usuario utec ON utec.id = s.tecnico_asignado_id
        ';

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY s.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

        $q = Database::conn()->prepare($sql);
        $q->execute($params);
        return $q->fetchAll();
    }

    /**
     * Obtiene el detalle completo de una solicitud con relaciones institucionales.
     */
    public function findDetailById(int $id): ?array
    {
        $sql = '
            SELECT 
                s.id, s.codigo_correlativo, s.sede_id, se.codigo_institucional AS sede_codigo, se.nombre AS sede_nombre,
                s.servicio_solicitante_id, ssol.nombre AS servicio_solicitante_nombre,
                s.servicio_resolutor_id, sres.nombre AS servicio_resolutor_nombre,
                s.tipo_solicitud_id, t.nombre AS tipo_solicitud_nombre, t.codigo_tipo, t.prioridad_sugerida,
                s.ubicacion_especifica, s.prioridad_asistencial, s.descripcion_falla,
                s.estado, s.es_expedita, s.usuario_solicitante_id, u.nombre AS usuario_solicitante_nombre,
                s.tecnico_asignado_id, utec.nombre AS tecnico_asignado_nombre,
                s.version, s.fecha_radicacion_utc, s.fecha_asignacion_utc, s.fecha_inicio_proceso_utc,
                s.fecha_atencion_utc, s.fecha_cierre_utc, s.updated_at_utc
            FROM ' . Solicitud::TABLE . ' s
            INNER JOIN org_sedes se ON se.id = s.sede_id
            INNER JOIN org_servicios ssol ON ssol.id = s.servicio_solicitante_id
            INNER JOIN org_servicios sres ON sres.id = s.servicio_resolutor_id
            INNER JOIN cat_solicitud_tipos t ON t.id = s.tipo_solicitud_id
            INNER JOIN auth_usuario u ON u.id = s.usuario_solicitante_id
            LEFT JOIN auth_usuario utec ON utec.id = s.tecnico_asignado_id
            WHERE s.id = :id
        ';
        $q = Database::conn()->prepare($sql);
        $q->execute(['id' => $id]);
        $row = $q->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Actualiza el estado de una solicitud implementando Control de Concurrencia Optimista (D-01).
     * Requiere que el número de versión coincida exactamente; de lo contrario falla atómicamente.
     *
     * @param int $id ID de la solicitud
     * @param int $versionEsperada Versión leída previamente por el cliente
     * @param string $nuevoEstado Nuevo estado de la máquina FSM
     * @param int|null $tecnicoAsignadoId ID del técnico asignado o null
     * @return bool True si se actualizó exactamente 1 fila, False si hubo conflicto de versión
     */
    public function updateEstadoConcurrente(
        int $id,
        int $versionEsperada,
        string $nuevoEstado,
        ?int $tecnicoAsignadoId = null
    ): bool {
        $sets = [
            'estado = :nuevo_estado',
            'version = version + 1',
            'updated_at_utc = UTC_TIMESTAMP()',
        ];
        $params = [
            'nuevo_estado' => $nuevoEstado,
            'id' => $id,
            'version_esperada' => $versionEsperada,
        ];

        if ($nuevoEstado === 'Asignada') {
            $sets[] = 'tecnico_asignado_id = :tecnico_asignado_id';
            $sets[] = 'fecha_asignacion_utc = COALESCE(fecha_asignacion_utc, UTC_TIMESTAMP())';
            $params['tecnico_asignado_id'] = $tecnicoAsignadoId;
        } elseif ($tecnicoAsignadoId !== null) {
            $sets[] = 'tecnico_asignado_id = :tecnico_asignado_id';
            $params['tecnico_asignado_id'] = $tecnicoAsignadoId;
        }

        if ($nuevoEstado === 'En Proceso') {
            $sets[] = 'fecha_inicio_proceso_utc = COALESCE(fecha_inicio_proceso_utc, UTC_TIMESTAMP())';
        } elseif ($nuevoEstado === 'Atendida') {
            $sets[] = 'fecha_atencion_utc = UTC_TIMESTAMP()';
        } elseif ($nuevoEstado === 'Cerrada') {
            $sets[] = 'fecha_cierre_utc = UTC_TIMESTAMP()';
        }

        $sql = "UPDATE " . Solicitud::TABLE . " SET " . implode(', ', $sets) . " WHERE id = :id AND version = :version_esperada";

        $q = Database::conn()->prepare($sql);
        $q->execute($params);

        return $q->rowCount() > 0;
    }

    /**
     * Obtiene la bitácora inmutable de transiciones de estado para una solicitud (RN-012/RN-013).
     * @return list<array>
     */
    public function getHistorialBySolicitudId(int $solicitudId): array
    {
        $sql = "
            SELECT 
                h.id,
                h.solicitud_id,
                h.estado_anterior,
                h.estado_nuevo,
                h.causal_codigo,
                h.motivo_texto,
                h.usuario_id,
                u.nombre AS usuario_nombre,
                u.email AS usuario_email,
                h.ip_origen,
                h.created_at_utc
            FROM sol_historial_estados h
            INNER JOIN auth_usuario u ON u.id = h.usuario_id
            WHERE h.solicitud_id = :solicitud_id
            ORDER BY h.id ASC
        ";

        $q = Database::conn()->prepare($sql);
        $q->execute(['solicitud_id' => $solicitudId]);
        return $q->fetchAll();
    }
}
