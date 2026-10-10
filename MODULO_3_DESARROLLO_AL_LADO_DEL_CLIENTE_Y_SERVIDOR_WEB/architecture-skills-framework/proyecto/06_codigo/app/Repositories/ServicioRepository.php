<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Servicio;

final class ServicioRepository
{
    private const COLUMNS = 'id, sede_id, codigo, nombre, tipo_servicio, ubicacion_piso_area, estado';

    /** @return list<Servicio> */
    public function findBySede(int $sedeId, ?string $tipo = null): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ' . Servicio::TABLE . ' WHERE sede_id = :sede_id AND estado = "activo"';
        $params = ['sede_id' => $sedeId];

        if ($tipo !== null) {
            $sql .= ' AND tipo_servicio = :tipo';
            $params['tipo'] = strtoupper($tipo);
        }

        $sql .= ' ORDER BY id';
        $q = Database::conn()->prepare($sql);
        $q->execute($params);
        $rows = $q->fetchAll();
        return array_map(Servicio::fromRow(...), $rows);
    }

    public function findById(int $id): ?Servicio
    {
        $q = Database::conn()->prepare('SELECT ' . self::COLUMNS . ' FROM ' . Servicio::TABLE . ' WHERE id = :id');
        $q->execute(['id' => $id]);
        $row = $q->fetch();
        return $row === false ? null : Servicio::fromRow($row);
    }

    public function exists(int $id): bool
    {
        $q = Database::conn()->prepare('SELECT 1 FROM ' . Servicio::TABLE . " WHERE id = :id AND estado = 'activo'");
        $q->execute(['id' => $id]);
        return (bool) $q->fetchColumn();
    }
}
