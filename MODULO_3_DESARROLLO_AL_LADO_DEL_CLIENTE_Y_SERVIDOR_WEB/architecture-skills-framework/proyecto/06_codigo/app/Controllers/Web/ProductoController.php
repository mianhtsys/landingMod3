<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\Request;
use App\Core\Session;
use App\Core\View;

// CP-FRONT-06: pantalla de listado de productos. Solo render HTML;
// los datos llegan por API desde productos.js (sin SQL/PDO).
final class ProductoController
{
    public function index(Request $request): never
    {
        if (!$this->hasSession()) {
            header('Location: /login');
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        View::render('productos/index', [
            'pageTitle' => 'Catálogo de Productos | S3IH',
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
