<?php
declare(strict_types=1);

namespace App\Models;

final class Servicio
{
    public const TABLE = 'org_servicios';

    public function __construct(
        public readonly int $id,
        public readonly int $sedeId,
        public readonly string $codigo,
        public readonly string $nombre,
        public readonly string $tipoServicio, // 'ASISTENCIAL' | 'RESOLUTOR'
        public readonly string $ubicacionPisoArea,
        public readonly string $estado,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['sede_id'],
            (string) $row['codigo'],
            (string) $row['nombre'],
            (string) $row['tipo_servicio'],
            (string) $row['ubicacion_piso_area'],
            (string) $row['estado'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sede_id' => $this->sedeId,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'tipo_servicio' => $this->tipoServicio,
            'ubicacion_piso_area' => $this->ubicacionPisoArea,
            'estado' => $this->estado,
        ];
    }
}
