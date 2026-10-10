<?php
declare(strict_types=1);

namespace App\Models;

final class Solicitud
{
    public const TABLE = 'sol_solicitudes';

    public function __construct(
        public readonly int $id,
        public readonly string $codigoCorrelativo,
        public readonly int $sedeId,
        public readonly int $servicioSolicitanteId,
        public readonly int $servicioResolutorId,
        public readonly int $tipoSolicitudId,
        public readonly string $ubicacionEspecifica,
        public readonly string $prioridadAsistencial, // 'VITAL' | 'ALTA' | 'MEDIA' | 'BAJA'
        public readonly string $descripcionFalla,
        public readonly string $estado, // 'Registrada', 'Asignada', 'En Proceso', 'En Espera', 'Atendida', 'Cerrada', 'Rechazada', 'Cancelada'
        public readonly bool $esExpedita,
        public readonly int $usuarioSolicitanteId,
        public readonly ?int $tecnicoAsignadoId,
        public readonly int $version,
        public readonly string $fechaRadicacionUtc,
        public readonly ?string $fechaAsignacionUtc,
        public readonly ?string $fechaInicioProcesoUtc,
        public readonly ?string $fechaAtencionUtc,
        public readonly ?string $fechaCierreUtc,
        public readonly string $updatedAtUtc,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['codigo_correlativo'],
            (int) $row['sede_id'],
            (int) $row['servicio_solicitante_id'],
            (int) $row['servicio_resolutor_id'],
            (int) $row['tipo_solicitud_id'],
            (string) $row['ubicacion_especifica'],
            (string) $row['prioridad_asistencial'],
            (string) $row['descripcion_falla'],
            (string) $row['estado'],
            (bool) ($row['es_expedita'] ?? 0),
            (int) $row['usuario_solicitante_id'],
            isset($row['tecnico_asignado_id']) && $row['tecnico_asignado_id'] !== null ? (int) $row['tecnico_asignado_id'] : null,
            (int) ($row['version'] ?? 1),
            (string) $row['fecha_radicacion_utc'],
            isset($row['fecha_asignacion_utc']) ? (string) $row['fecha_asignacion_utc'] : null,
            isset($row['fecha_inicio_proceso_utc']) ? (string) $row['fecha_inicio_proceso_utc'] : null,
            isset($row['fecha_atencion_utc']) ? (string) $row['fecha_atencion_utc'] : null,
            isset($row['fecha_cierre_utc']) ? (string) $row['fecha_cierre_utc'] : null,
            (string) $row['updated_at_utc'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'codigo_correlativo' => $this->codigoCorrelativo,
            'sede_id' => $this->sedeId,
            'servicio_solicitante_id' => $this->servicioSolicitanteId,
            'servicio_resolutor_id' => $this->servicioResolutorId,
            'tipo_solicitud_id' => $this->tipoSolicitudId,
            'ubicacion_especifica' => $this->ubicacionEspecifica,
            'prioridad_asistencial' => $this->prioridadAsistencial,
            'descripcion_falla' => $this->descripcionFalla,
            'estado' => $this->estado,
            'es_expedita' => $this->esExpedita,
            'usuario_solicitante_id' => $this->usuarioSolicitanteId,
            'tecnico_asignado_id' => $this->tecnicoAsignadoId,
            'version' => $this->version,
            'fecha_radicacion_utc' => $this->fechaRadicacionUtc,
            'fecha_asignacion_utc' => $this->fechaAsignacionUtc,
            'fecha_inicio_proceso_utc' => $this->fechaInicioProcesoUtc,
            'fecha_atencion_utc' => $this->fechaAtencionUtc,
            'fecha_cierre_utc' => $this->fechaCierreUtc,
            'updated_at_utc' => $this->updatedAtUtc,
        ];
    }
}
