<?php

declare(strict_types=1);

use App\Controllers\Api\AuthController;
use App\Controllers\Api\CategoriaController;
use App\Controllers\Api\ProductoController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

return [
    // CP-API-02: Health check
    ['GET', '/api/v1/health', function (Request $request): never {
        if (!Database::ping()) {
            Response::fail(500, 'Error de base de datos');
        }
        Response::json(200, [
            'success' => true,
            'data' => [
                'status' => 'ok',
                'db' => 'up',
                'php' => PHP_VERSION,
                'app' => getenv('APP_NAME') ?: 'S3IH - Sistema Hospitalario',
                'timestamp' => date('c')
            ],
            'message' => 'Servicio hospitalario operativo y saludable'
        ]);
    }],

    // CP-API-08: Documentación OpenAPI 3.1
    ['GET', '/api/v1/openapi.yaml', function (Request $request): never {
        $file = dirname(__DIR__) . '/openapi_categorias.yaml';
        if (!is_file($file)) {
            Response::fail(404, 'Especificación OpenAPI no encontrada');
        }
        header('Content-Type: application/x-yaml; charset=UTF-8');
        echo file_get_contents($file);
        exit;
    }],

    // CP-API-03 a CP-API-07: Módulo Piloto de Categorías Hospitalarias
    ['GET', '/api/v1/categorias', [CategoriaController::class, 'index']],
    ['POST', '/api/v1/categorias', [CategoriaController::class, 'store']],
    ['GET', '/api/v1/categorias/{id}', [CategoriaController::class, 'show']],
    ['PUT', '/api/v1/categorias/{id}', [CategoriaController::class, 'update']],
    ['DELETE', '/api/v1/categorias/{id}', [CategoriaController::class, 'destroy']],

    // Endpoints de módulos subsecuentes (Workflow 04)
    ['POST', '/api/v1/auth/login', [AuthController::class, 'login']],
    ['POST', '/api/v1/auth/logout', [AuthController::class, 'logout']],
    ['GET', '/api/v1/auth/me', [AuthController::class, 'me']],
    ['GET', '/api/v1/productos', [ProductoController::class, 'index']],
    ['POST', '/api/v1/productos', [ProductoController::class, 'store']],
    ['GET', '/api/v1/productos/{id}', [ProductoController::class, 'show']],
    ['PUT', '/api/v1/productos/{id}', [ProductoController::class, 'update']],
    ['DELETE', '/api/v1/productos/{id}', [ProductoController::class, 'destroy']],

    // Workflow 08 (CP-SOL-03): Módulo Central de Solicitudes Hospitalarias (S3IH)
    ['GET', '/api/v1/solicitudes', [\App\Controllers\Api\SolicitudApiController::class, 'index']],
    ['POST', '/api/v1/solicitudes', [\App\Controllers\Api\SolicitudApiController::class, 'store']],
    ['POST', '/api/v1/solicitudes/expedita', [\App\Controllers\Api\SolicitudApiController::class, 'storeExpedita']],
    ['GET', '/api/v1/solicitudes/{id}', [\App\Controllers\Api\SolicitudApiController::class, 'show']],

    // Workflow 08 (CP-SOL-04): Máquina de Estados Finita y Concurrencia Optimista
    ['POST', '/api/v1/solicitudes/{id}/transiciones', [\App\Controllers\Api\SolicitudApiController::class, 'transicionar']],
    ['GET', '/api/v1/solicitudes/{id}/historial', [\App\Controllers\Api\SolicitudApiController::class, 'historial']],
];
