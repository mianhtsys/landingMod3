<?php
declare(strict_types=1);

namespace App\Validators;

use RuntimeException;

final class SolicitudValidator
{
    private const PRIORIDADES_VALIDAS = ['VITAL', 'ALTA', 'MEDIA', 'BAJA'];

    /**
     * Valida la política de seguridad hospitalaria Zero-PHI (RNF-035).
     * Prohíbe terminantemente el ingreso de datos de pacientes, diagnósticos médicos
     * o números de historia clínica/cédula en campos de soporte técnico.
     */
    public function validateZeroPhi(string $text, string $fieldName): void
    {
        $patterns = [
            'identificacion_paciente' => '/\b(?:ci|dni|c[eé]dula|carnet|pasaporte)[\s:#._-]*\d{4,10}\b/iu',
            'historia_clinica' => '/\b(?:hc|h\.c\.|historia\s*cl[ií]nica|expediente\s*cl[ií]nico|nro\s*historia|ficha\s*m[eé]dica)[\s:#._-]*\d+\b/iu',
            'nombre_paciente' => '/\b(?:paciente|nombre\s*del?\s*paciente)[\s:]+[a-záéíóúñ]{2,}\s+[a-záéíóúñ]{2,}\b/iu',
            'diagnostico_medico' => '/\b(?:diagn[oó]stico|dx\s*m[eé]dico)[\s:]+[a-záéíóúñ]{3,}\b/iu',
        ];

        foreach ($patterns as $tipo => $pattern) {
            if (preg_match($pattern, $text)) {
                throw new RuntimeException(
                    "Violación de política Zero-PHI (RNF-035) en {$fieldName}: No se permite registrar información médica protegida, datos personales ni números de historia clínica o cédula en solicitudes de infraestructura.",
                    422
                );
            }
        }
    }

    /**
     * Valida un identificador primario numérico entero.
     */
    public function validateId(mixed $raw): int
    {
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }
        throw new RuntimeException('ID de solicitud inválido', 400);
    }

    /**
     * Valida la clave de idempotencia opcional (UUIDv4 o token alfanumérico seguro).
     */
    public function validateIdempotencyKey(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }
        $trimmed = trim($key);
        if ($trimmed === '') {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $trimmed)) {
            throw new RuntimeException(
                'Idempotency-Key inválida: debe contener entre 8 y 64 caracteres alfanuméricos o guiones (ej. UUIDv4)',
                400
            );
        }
        return $trimmed;
    }

    /**
     * Valida el payload de una solicitud estándar ordinaria (POST /api/v1/solicitudes).
     * @return array Datos validados y tipados
     */
    public function validateStoreBody(?array $body): array
    {
        if ($body === null) {
            throw new RuntimeException('Cuerpo JSON requerido', 400);
        }

        $sedeId = $this->requirePositiveInt($body, 'sede_id', 'Sede hospitalaria');
        $srvSolId = $this->requirePositiveInt($body, 'servicio_solicitante_id', 'Servicio solicitante');
        $srvResId = $this->requirePositiveInt($body, 'servicio_resolutor_id', 'Servicio resolutor');
        $tipoId = $this->requirePositiveInt($body, 'tipo_solicitud_id', 'Tipo de solicitud');

        $ubicacion = $this->requireString($body, 'ubicacion_especifica', 3, 150, 'Ubicación específica');
        $this->validateZeroPhi($ubicacion, 'ubicacion_especifica');

        $descripcion = $this->requireString($body, 'descripcion_falla', 5, 2000, 'Descripción de la falla');
        $this->validateZeroPhi($descripcion, 'descripcion_falla');

        $prioridad = isset($body['prioridad_asistencial']) ? strtoupper(trim((string) $body['prioridad_asistencial'])) : 'MEDIA';
        if (!in_array($prioridad, self::PRIORIDADES_VALIDAS, true)) {
            throw new RuntimeException('Prioridad asistencial inválida. Opciones: VITAL, ALTA, MEDIA, BAJA', 400);
        }

        $usuarioSolId = isset($body['usuario_solicitante_id']) ? (int) $body['usuario_solicitante_id'] : 1;
        if ($usuarioSolId < 1) {
            throw new RuntimeException('usuario_solicitante_id debe ser un entero positivo', 400);
        }

        return [
            'sede_id' => $sedeId,
            'servicio_solicitante_id' => $srvSolId,
            'servicio_resolutor_id' => $srvResId,
            'tipo_solicitud_id' => $tipoId,
            'ubicacion_especifica' => $ubicacion,
            'prioridad_asistencial' => $prioridad,
            'descripcion_falla' => $descripcion,
            'usuario_solicitante_id' => $usuarioSolId,
            'es_expedita' => 0,
        ];
    }

    /**
     * Valida el payload de una solicitud expedita de soporte vital (POST /api/v1/solicitudes/expedita).
     * @return array Datos validados y forzados a prioridad VITAL
     */
    public function validateExpeditaBody(?array $body): array
    {
        if ($body === null) {
            throw new RuntimeException('Cuerpo JSON requerido', 400);
        }

        // En modo expedito para quirófanos/UCI se permiten valores por defecto inteligentes
        $sedeId = isset($body['sede_id']) ? (int) $body['sede_id'] : 1;
        if ($sedeId < 1) {
            throw new RuntimeException('sede_id inválido', 400);
        }

        $srvSolId = isset($body['servicio_solicitante_id']) ? (int) $body['servicio_solicitante_id'] : 1; // UCI Central
        $srvResId = isset($body['servicio_resolutor_id']) ? (int) $body['servicio_resolutor_id'] : 4;   // Ingeniería Biomédica
        $tipoId = isset($body['tipo_solicitud_id']) ? (int) $body['tipo_solicitud_id'] : 1;           // BIO-VENT-FALLA

        $ubicacion = $this->requireString($body, 'ubicacion_especifica', 3, 150, 'Ubicación de soporte vital');
        $this->validateZeroPhi($ubicacion, 'ubicacion_especifica');

        $descripcion = $this->requireString($body, 'descripcion_falla', 5, 2000, 'Descripción de la emergencia vital');
        $this->validateZeroPhi($descripcion, 'descripcion_falla');

        $usuarioSolId = isset($body['usuario_solicitante_id']) ? (int) $body['usuario_solicitante_id'] : 1;
        if ($usuarioSolId < 1) {
            throw new RuntimeException('usuario_solicitante_id debe ser un entero positivo', 400);
        }

        return [
            'sede_id' => $sedeId,
            'servicio_solicitante_id' => $srvSolId,
            'servicio_resolutor_id' => $srvResId,
            'tipo_solicitud_id' => $tipoId,
            'ubicacion_especifica' => $ubicacion,
            'prioridad_asistencial' => 'VITAL', // RN-005: Siempre forzado a VITAL en expedita
            'descripcion_falla' => $descripcion,
            'usuario_solicitante_id' => $usuarioSolId,
            'es_expedita' => 1,
        ];
    }

    private function requirePositiveInt(array $body, string $field, string $label): int
    {
        if (!isset($body[$field]) || (!is_int($body[$field]) && !ctype_digit((string) $body[$field]))) {
            throw new RuntimeException("{$label} ({$field}) es obligatorio y debe ser un ID numérico entero", 400);
        }
        $val = (int) $body[$field];
        if ($val < 1) {
            throw new RuntimeException("{$label} ({$field}) debe ser mayor que 0", 400);
        }
        return $val;
    }

    private function requireString(array $body, string $field, int $min, int $max, string $label): string
    {
        if (!isset($body[$field]) || !is_string($body[$field])) {
            throw new RuntimeException("{$label} ({$field}) es obligatorio y debe ser una cadena de texto", 400);
        }
        $val = trim($body[$field]);
        $len = mb_strlen($val, 'UTF-8');
        if ($len < $min || $len > $max) {
            throw new RuntimeException("{$label} ({$field}) debe tener entre {$min} y {$max} caracteres", 400);
        }
        return $val;
    }

    /**
     * Valida el payload de una transición de estado en la máquina FSM con concurrencia optimista.
     * @return array{nuevo_estado: string, version: int, tecnico_asignado_id: int|null, motivo: string|null, causal: string|null}
     */
    public function validateTransitionBody(?array $body): array
    {
        if ($body === null) {
            throw new RuntimeException('Cuerpo JSON requerido', 400);
        }

        if (empty($body['nuevo_estado']) || !is_string($body['nuevo_estado'])) {
            throw new RuntimeException("El campo 'nuevo_estado' es obligatorio", 400);
        }
        $nuevoEstado = trim($body['nuevo_estado']);

        if (!isset($body['version']) || (!is_int($body['version']) && !ctype_digit((string) $body['version']))) {
            throw new RuntimeException("El campo 'version' es obligatorio para el control de concurrencia optimista (D-01)", 400);
        }
        $version = (int) $body['version'];
        if ($version < 1) {
            throw new RuntimeException("El número de versión debe ser mayor o igual a 1", 400);
        }

        $tecnicoId = null;
        if ($nuevoEstado === 'Asignada') {
            if (empty($body['tecnico_asignado_id']) || (!is_int($body['tecnico_asignado_id']) && !ctype_digit((string) $body['tecnico_asignado_id']))) {
                throw new RuntimeException("Para asignar la orden hospitalaria es obligatorio indicar 'tecnico_asignado_id'", 400);
            }
            $tecnicoId = (int) $body['tecnico_asignado_id'];
        } elseif (isset($body['tecnico_asignado_id']) && is_numeric($body['tecnico_asignado_id'])) {
            $tecnicoId = (int) $body['tecnico_asignado_id'];
        }

        $motivo = isset($body['motivo']) && is_string($body['motivo']) ? trim($body['motivo']) : '';
        $causal = isset($body['causal']) && is_string($body['causal']) ? trim($body['causal']) : null;

        if (in_array($nuevoEstado, ['Rechazada', 'Cancelada', 'En Espera'], true)) {
            if ($motivo === '' || mb_strlen($motivo, 'UTF-8') < 5) {
                throw new RuntimeException("La transición a '{$nuevoEstado}' requiere una justificación o motivo explicativo de al menos 5 caracteres", 400);
            }
        }

        if ($motivo !== '') {
            $this->validateZeroPhi($motivo, 'motivo');
        }

        return [
            'nuevo_estado' => $nuevoEstado,
            'version' => $version,
            'tecnico_asignado_id' => $tecnicoId,
            'motivo' => $motivo !== '' ? $motivo : null,
            'causal' => $causal,
        ];
    }
}
