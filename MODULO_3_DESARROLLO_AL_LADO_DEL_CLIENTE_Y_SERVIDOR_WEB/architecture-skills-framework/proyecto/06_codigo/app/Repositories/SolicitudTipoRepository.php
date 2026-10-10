<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\SolicitudTipo;

final class SolicitudTipoRepository
{
    private const COLUMNS = 'id, servicio_resolutor_id, codigo_tipo, nombre, descripcion, prioridad_sugerida, estado';

    /** @return list<SolicitudTipo> */
    public function findAllActivos(): array
    {
        $rows = Database::conn()
            ->query('SELECT ' . self::COLUMNS . " FROM " . SolicitudTipo::TABLE . " WHERE estado = 'activo' ORDER BY id")
            ->fetchAll();
        return array_map(SolicitudTipo::fromRow(...), $rows);
    }

    public function findById(int $id): ?SolicitudTipo
    {
        $q = Database::conn()->prepare('SELECT ' . self::COLUMNS . ' FROM ' . SolicitudTipo::TABLE . ' WHERE id = :id');
        $q->execute(['id' => $id]);
        $row = $q->fetch();
        return $row === false ? null : SolicitudTipo::fromRow($row);
    }

    public function exists(int $id): bool
    {
        $q = Database::conn()->prepare('SELECT 1 FROM ' . SolicitudTipo::TABLE . " WHERE id = :id AND estado = 'activo'");
        $q->execute(['id' => $id]);
        return (bool) $q->fetchColumn();
    }
}
