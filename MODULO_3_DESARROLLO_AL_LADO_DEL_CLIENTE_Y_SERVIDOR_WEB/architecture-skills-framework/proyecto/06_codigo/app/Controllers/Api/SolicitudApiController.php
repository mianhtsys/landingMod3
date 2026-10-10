<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\SolicitudService;
use App\Validators\SolicitudValidator;

final class SolicitudApiController
{
    public function __construct(
        private readonly SolicitudValidator $validator = new SolicitudValidator(),
        private readonly SolicitudService $service = new SolicitudService(),
    ) {
    }

    /**
     * POST /api/v1/solicitudes
     * Radicación ordinaria/estándar de orden de trabajo hospitalario.
     */
    public function store(Request $request): never
    {
        Response::execute(function () use ($request): array {
            $data = $this->validator->validateStoreBody($request->body());
            $idempotencyKey = $this->validator->validateIdempotencyKey($request->header('Idempotency-Key'));

            Session::start();
            if (!empty($_SESSION['user_id'])) {
                $data['usuario_solicitante_id'] = (int) $_SESSION['user_id'];
            }

            return $this->service->create($data, $idempotencyKey, $request->ip());
        }, 201);
    }

    /**
     * POST /api/v1/solicitudes/expedita
     * Radicación expedita de soporte vital (UCI / Quirófano).
     * Forzado determinista a prioridad asistencial VITAL.
     */
    public function storeExpedita(Request $request): never
    {
        Response::execute(function () use ($request): array {
            $data = $this->validator->validateExpeditaBody($request->body());
            $idempotencyKey = $this->validator->validateIdempotencyKey($request->header('Idempotency-Key'));

            Session::start();
            if (!empty($_SESSION['user_id'])) {
                $data['usuario_solicitante_id'] = (int) $_SESSION['user_id'];
            }

            return $this->service->createExpedita($data, $idempotencyKey, $request->ip());
        }, 201);
    }

    /**
     * GET /api/v1/solicitudes
     * Listado general con soporte de filtros hospitalarios.
     */
    public function index(Request $request): never
    {
        Response::execute(function (): array {
            $filters = [];
            if (isset($_GET['sede_id'])) {
                $filters['sede_id'] = (int) $_GET['sede_id'];
            }
            if (!empty($_GET['estado'])) {
                $filters['estado'] = (string) $_GET['estado'];
            }
            if (!empty($_GET['prioridad_asistencial'])) {
                $filters['prioridad_asistencial'] = (string) $_GET['prioridad_asistencial'];
            }
            if (isset($_GET['es_expedita'])) {
                $filters['es_expedita'] = (int) $_GET['es_expedita'];
            }
            return $this->service->list($filters);
        }, 200);
    }

    /**
     * GET /api/v1/solicitudes/{id}
     * Detalle institucional completo de una orden hospitalaria.
     */
    public function show(Request $request): never
    {
        Response::execute(function () use ($request): array {
            $id = $this->validator->validateId($request->param('id'));
            return $this->service->get($id);
        }, 200);
    }

    /**
     * POST /api/v1/solicitudes/{id}/transiciones
     * Ejecuta una transición de estado en la FSM con control de concurrencia optimista (D-01).
     */
    public function transicionar(Request $request): never
    {
        Response::execute(function () use ($request): array {
            $id = $this->validator->validateId($request->param('id'));
            $data = $this->validator->validateTransitionBody($request->body());

            Session::start();
            $usuarioId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 1;

            return $this->service->cambiarEstado(
                id: $id,
                nuevoEstado: $data['nuevo_estado'],
                versionEsperada: $data['version'],
                tecnicoAsignadoId: $data['tecnico_asignado_id'],
                motivo: $data['motivo'],
                causal: $data['causal'],
                usuarioId: $usuarioId,
                clientIp: $request->ip()
            );
        }, 200);
    }

    /**
     * GET /api/v1/solicitudes/{id}/historial
     * Retorna la bitácora cronológica inmutable de transiciones (Audit Trail).
     */
    public function historial(Request $request): never
    {
        Response::execute(function () use ($request): array {
            $id = $this->validator->validateId($request->param('id'));
            return $this->service->getHistorial($id);
        }, 200);
    }
}
