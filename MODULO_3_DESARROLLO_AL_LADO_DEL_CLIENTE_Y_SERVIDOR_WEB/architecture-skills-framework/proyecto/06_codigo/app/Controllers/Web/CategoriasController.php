<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\Request;
use App\Core\Session;
use App\Core\View;

// CP-FRONT-03: pantalla de listado de categorías. Solo render HTML;
// los datos llegan por API desde categorias.js (sin SQL/PDO).
final class CategoriasController
{
    public function index(Request $request): never
    {
        if (!$this->hasSession()) {
            header('Location: /login');
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        View::render('categorias/index', [
            'pageTitle' => 'Categorías de Suministros | S3IH',
            'userName' => (string) ($_SESSION['nombre'] ?? 'Personal S3IH'),
        ], 'layouts/main');
    }

    private function hasSession(): bool
    {
        if (!isset($_COOKIE[Session::name()])) {
            return false;
        }
        Session::start();
        return isset($_SESSION['user_id']);
    }
}
