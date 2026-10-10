<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Sede;

final class SedeRepository
{
    private const COLUMNS = 'id, codigo_institucional, nombre, direccion, telefono, es_central, estado';

    /** @return list<Sede> */
    public function findAllActivas(): array
    {
        $rows = Database::conn()
            ->query('SELECT ' . self::COLUMNS . " FROM " . Sede::TABLE . " WHERE estado = 'activo' ORDER BY id")
            ->fetchAll();
        return array_map(Sede::fromRow(...), $rows);
    }

    public function findById(int $id): ?Sede
    {
        $q = Database::conn()->prepare('SELECT ' . self::COLUMNS . ' FROM ' . Sede::TABLE . ' WHERE id = :id');
        $q->execute(['id' => $id]);
        $row = $q->fetch();
        return $row === false ? null : Sede::fromRow($row);
    }

    public function findByCodigo(string $codigo): ?Sede
    {
        $q = Database::conn()->prepare('SELECT ' . self::COLUMNS . ' FROM ' . Sede::TABLE . ' WHERE codigo_institucional = :codigo');
        $q->execute(['codigo' => strtoupper($codigo)]);
        $row = $q->fetch();
        return $row === false ? null : Sede::fromRow($row);
    }

    public function exists(int $id): bool
    {
        $q = Database::conn()->prepare('SELECT 1 FROM ' . Sede::TABLE . " WHERE id = :id AND estado = 'activo'");
        $q->execute(['id' => $id]);
        return (bool) $q->fetchColumn();
    }
}
