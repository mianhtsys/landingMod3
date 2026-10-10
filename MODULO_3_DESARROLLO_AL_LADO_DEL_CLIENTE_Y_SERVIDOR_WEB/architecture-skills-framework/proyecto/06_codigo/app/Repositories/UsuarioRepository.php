<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Usuario;

final class UsuarioRepository
{
    public function findByEmail(string $email): ?Usuario
    {
        $q = Database::conn()->prepare(
            'SELECT id, nombre, email, estado, hash_password FROM ' . Usuario::TABLE . ' WHERE email = :email LIMIT 1'
        );
        $q->execute(['email' => $email]);
        $row = $q->fetch();
        return $row === false ? null : Usuario::fromRow($row);
    }

    public function findById(int $id): ?Usuario
    {
        $q = Database::conn()->prepare(
            'SELECT id, nombre, email, estado, hash_password FROM ' . Usuario::TABLE . ' WHERE id = :id LIMIT 1'
        );
        $q->execute(['id' => $id]);
        $row = $q->fetch();
        return $row === false ? null : Usuario::fromRow($row);
    }
}
