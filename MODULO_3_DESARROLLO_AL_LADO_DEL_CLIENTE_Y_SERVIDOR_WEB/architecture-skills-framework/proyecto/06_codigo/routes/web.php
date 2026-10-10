<?php
declare(strict_types=1);

use App\Controllers\Web\AuthController;
use App\Controllers\Web\CategoriasController;
use App\Controllers\Web\DashboardController;
use App\Controllers\Web\ProductoController;
use App\Core\Request;
use App\Core\Session;

// CP-FRONT-02: rutas web (pantallas). Solo GET; la interacción va por API/JS.
$redirect = static function (string $to): never {
    header('Location: ' . $to);
    exit;
};

return [
    // CP-FRONT-02: raíz → dashboard si hay sesión, si no → login
    ['GET', '/', static function (Request $r) use ($redirect): never {
        $logged = isset($_COOKIE[Session::name()]);
        $redirect($logged ? '/dashboard' : '/login');
    }],
    ['GET', '/login', [AuthController::class, 'showLogin']],
    ['GET', '/dashboard', [DashboardController::class, 'index']],
    ['GET', '/categorias', [CategoriasController::class, 'index']],
    ['GET', '/productos', [ProductoController::class, 'index']],

    // Workflow 08 (CP-SOL-05..06): Interfaz Web Asistencial: Bandeja, Radicación y Detalle FSM
    ['GET', '/solicitudes', [\App\Controllers\Web\SolicitudesController::class, 'index']],
    ['GET', '/solicitudes/radicar', [\App\Controllers\Web\SolicitudesController::class, 'radicar']],
    ['GET', '/solicitudes/{id}', [\App\Controllers\Web\SolicitudesController::class, 'show']],
];
