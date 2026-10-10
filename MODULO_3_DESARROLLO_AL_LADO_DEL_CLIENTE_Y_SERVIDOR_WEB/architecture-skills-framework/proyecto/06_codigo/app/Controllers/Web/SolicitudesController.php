<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\Request;
use App\Core\Session;
use App\Core\View;

final class SolicitudesController
{
    /**
     * GET /solicitudes
     * Bandeja de trabajo asistencial hospitalaria. Cero SQL en vista; consume API Fetch.
     */
    public function index(Request $request): never
    {
        if (!$this->hasSession()) {
            header('Location: /login');
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        View::render('solicitudes/index', [
            'pageTitle' => 'Bandeja de Solicitudes Hospitalarias | S3IH',
            'userName' => (string) ($_SESSION['nombre'] ?? 'Personal S3IH'),
        ], 'layouts/main');
    }

    /**
     * GET /solicitudes/radicar
     * Formulario de radicación dual (Estándar y Expedita de Soporte Vital).
     */
    public function radicar(Request $request): never
    {
        if (!$this->hasSession()) {
            header('Location: /login');
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        View::render('solicitudes/radicar', [
            'pageTitle' => 'Radicar Solicitud Hospitalaria | S3IH',
            'userName' => (string) ($_SESSION['nombre'] ?? 'Personal S3IH'),
        ], 'layouts/main');
    }

    /**
     * GET /solicitudes/{id}
     * Ficha técnica institucional, timeline inmutable de auditoría y botonera FSM.
     */
    public function show(Request $request): never
    {
        if (!$this->hasSession()) {
            header('Location: /login');
            exit;
        }
        $id = (int) $request->param('id');
        if ($id <= 0) {
            header('Location: /solicitudes');
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        View::render('solicitudes/show', [
            'pageTitle' => "Detalle de Orden #{$id} | S3IH",
            'userName' => (string) ($_SESSION['nombre'] ?? 'Personal S3IH'),
            'solicitudId' => $id,
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
