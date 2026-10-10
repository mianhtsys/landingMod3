<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\CategoriaService;
use App\Validators\CategoriaValidator;

final class CategoriaController
{
    public function __construct(
        private readonly CategoriaValidator $validator = new CategoriaValidator(),
        private readonly CategoriaService $service = new CategoriaService(),
    ) {
    }

    public function index(Request $request): never
    {
        Response::execute(function (): array {
            return $this->service->list();
        }, 200);
    }

    public function show(Request $request): never
    {
        Response::execute(function () use ($request): array {
            $id = $this->validator->validateId($request->param('id'));
            return $this->service->get($id);
        }, 200);
    }

    public function store(Request $request): never
    {
        Response::execute(function () use ($request): array {
            $data = $this->validator->validateBody($request->body());
            return $this->service->create($data);
        }, 201);
    }

    public function update(Request $request): never
    {
        Response::execute(function () use ($request): array {
            $id = $this->validator->validateId($request->param('id'));
            $data = $this->validator->validateBody($request->body());
            return $this->service->update($id, $data);
        }, 200);
    }

    // CP-API-07: DELETE lógico → estado='inactivo'
    public function destroy(Request $request): never
    {
        Response::execute(function () use ($request): array {
            $id = $this->validator->validateId($request->param('id'));
            return $this->service->deactivate($id);
        }, 200);
    }
}
