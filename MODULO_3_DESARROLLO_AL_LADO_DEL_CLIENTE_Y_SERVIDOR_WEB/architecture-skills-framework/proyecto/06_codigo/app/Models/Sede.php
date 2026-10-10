<?php
declare(strict_types=1);

namespace App\Models;

final class Sede
{
    public const TABLE = 'org_sedes';

    public function __construct(
        public readonly int $id,
        public readonly string $codigoInstitucional,
        public readonly string $nombre,
        public readonly ?string $direccion,
        public readonly ?string $telefono,
        public readonly bool $esCentral,
        public readonly string $estado,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['codigo_institucional'],
            (string) $row['nombre'],
            isset($row['direccion']) ? (string) $row['direccion'] : null,
            isset($row['telefono']) ? (string) $row['telefono'] : null,
            (bool) ($row['es_central'] ?? 0),
            (string) $row['estado'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'codigo_institucional' => $this->codigoInstitucional,
            'nombre' => $this->nombre,
            'direccion' => $this->direccion,
            'telefono' => $this->telefono,
            'es_central' => $this->esCentral,
            'estado' => $this->estado,
        ];
    }
}
