<?php
declare(strict_types=1);

namespace App\Models;

final class SolicitudTipo
{
    public const TABLE = 'cat_solicitud_tipos';

    public function __construct(
        public readonly int $id,
        public readonly int $servicioResolutorId,
        public readonly string $codigoTipo,
        public readonly string $nombre,
        public readonly ?string $descripcion,
        public readonly string $prioridadSugerida, // 'VITAL' | 'ALTA' | 'MEDIA' | 'BAJA'
        public readonly string $estado,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['servicio_resolutor_id'],
            (string) $row['codigo_tipo'],
            (string) $row['nombre'],
            isset($row['descripcion']) ? (string) $row['descripcion'] : null,
            (string) $row['prioridad_sugerida'],
            (string) $row['estado'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'servicio_resolutor_id' => $this->servicioResolutorId,
            'codigo_tipo' => $this->codigoTipo,
            'nombre' => $this->nombre,
            'descripcion' => $this->descripcion,
            'prioridad_sugerida' => $this->prioridadSugerida,
            'estado' => $this->estado,
        ];
    }
}
