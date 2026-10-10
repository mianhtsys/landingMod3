<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\Request;
use App\Core\Session;
use App\Core\View;

// CP-FRONT-02: dashboard protegido. Lee solo la sesión (sin SQL/PDO);
// los conteos los carga el JS desde la API.
final class DashboardController
{
    public function index(Request $request): never
    {
        $user = $this->currentUser();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        View::render('dashboard/index', [
            'pageTitle' => 'Centro de Control | S3IH',
            'userName' => $user['nombre'],
        ], 'layouts/main');
    }

    /** @return array{id:int,nombre:string,email:string}|null */
    private function currentUser(): ?array
    {
        if (!isset($_COOKIE[Session::name()])) {
            return null;
        }
        Session::start();
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        return [
            'id' => (int) $_SESSION['user_id'],
            'nombre' => (string) $_SESSION['nombre'],
            'email' => (string) $_SESSION['email'],
        ];
    }
}
