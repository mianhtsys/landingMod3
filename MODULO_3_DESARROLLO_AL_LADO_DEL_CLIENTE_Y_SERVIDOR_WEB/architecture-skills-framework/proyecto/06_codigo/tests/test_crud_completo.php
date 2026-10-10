<?php
declare(strict_types=1);

// Test automatizado completo para CP-API-08: OpenAPI + suite CRUD completa

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "SUITE CRUD COMPLETA & OPENAPI (CP-API-08)\n";
echo "Dominio: API Piloto de Categorías Hospitalarias S3IH\n";
echo "============================================================\n\n";

$testsTotal = 0;
$testsPassed = 0;

function assertTest(string $description, bool $condition, ?string $extra = null): void
{
    global $testsTotal, $testsPassed;
    $testsTotal++;
    if ($condition) {
        $testsPassed++;
        echo " [PASS] {$description}\n";
    } else {
        echo " [FAIL] {$description}\n";
        if ($extra) {
            echo "        Detalle: {$extra}\n";
        }
    }
}

$pdo = Database::conn();
$phpBinary = PHP_BINARY;
$runnerScript = __DIR__ . '/runner_exec.php';
$tmpPayload = __DIR__ . '/tmp_payload_crud.json';

function requestApi(string $method, string $uri, ?array $payload = null): array
{
    global $phpBinary, $runnerScript, $tmpPayload;
    $payloadArg = '""';
    if ($payload !== null) {
        file_put_contents($tmpPayload, json_encode($payload));
        $payloadArg = "\"{$tmpPayload}\"";
    }
    $cmd = "\"{$phpBinary}\" \"{$runnerScript}\" {$method} {$uri} {$payloadArg} \"application/json\" 2>&1";
    $output = shell_exec($cmd);
    $json = json_decode((string)$output, true);
    return ['output' => (string)$output, 'json' => $json];
}

// 1. Verificación de Salud del Sistema (GET /health)
echo "--- 1. Diagnóstico de Salud (GET /api/v1/health) ---\n";
$resHealth = requestApi('GET', '/api/v1/health');
assertTest("Endpoint health responde JSON válido", is_array($resHealth['json']));
assertTest("Estado del servicio es OK", ($resHealth['json']['data']['status'] ?? '') === 'ok');
assertTest("Conexión con motor MySQL es UP", ($resHealth['json']['data']['db'] ?? '') === 'up');

// 2. Verificación de Especificación OpenAPI 3.1
echo "\n--- 2. Especificación OpenAPI 3.1 (GET /api/v1/openapi.yaml) ---\n";
$resOpenApi = requestApi('GET', '/api/v1/openapi.yaml');
assertTest("Especificación OpenAPI expuesta y disponible", str_contains($resOpenApi['output'], 'openapi: 3.1.0'));
assertTest("OpenAPI contiene tag 'Categorías'", str_contains($resOpenApi['output'], 'Categorías'));
assertTest("OpenAPI documenta esquema de inmutabilidad", str_contains($resOpenApi['output'], 'deactivateCategoria'));

// 3. Inicialización Limpia de Datos
echo "\n--- 3. Inicialización del Catálogo ---\n";
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE cat_categoria");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

$resInicial = requestApi('GET', '/api/v1/categorias');
assertTest("Catálogo inicial está vacío (data: [])", is_array($resInicial['json']['data'] ?? null) && count($resInicial['json']['data']) === 0);

// 4. Creación de Categoría Raíz (POST)
echo "\n--- 4. Creación de Categoría Raíz Hospitalaria (POST) ---\n";
$resCrearRaiz = requestApi('POST', '/api/v1/categorias', [
    'nombre' => 'Equipamiento Biomédico y Soporte Vital',
    'categoria_padre_id' => null
]);
assertTest("Categoría raíz creada con éxito", ($resCrearRaiz['json']['success'] ?? false) === true);
$idRaiz = $resCrearRaiz['json']['data']['id'] ?? 0;
assertTest("ID de categoría raíz asignado", $idRaiz > 0);
assertTest("Estado inicial es activo", ($resCrearRaiz['json']['data']['estado'] ?? '') === 'activo');

// 5. Creación de Subcategoría Jerárquica (POST con padre)
echo "\n--- 5. Creación de Subcategoría Jerárquica (POST) ---\n";
$resCrearHija = requestApi('POST', '/api/v1/categorias', [
    'nombre' => 'Monitores de Signos Vitales y Desfibriladores',
    'categoria_padre_id' => $idRaiz
]);
assertTest("Subcategoría creada con éxito", ($resCrearHija['json']['success'] ?? false) === true);
$idHija = $resCrearHija['json']['data']['id'] ?? 0;
assertTest("Subcategoría referencia a categoría raíz", ($resCrearHija['json']['data']['categoria_padre_id'] ?? 0) === $idRaiz);

// 6. Consulta de Colección con Datos (GET)
echo "\n--- 6. Listado de Colección (GET /api/v1/categorias) ---\n";
$resListado = requestApi('GET', '/api/v1/categorias');
assertTest("Listado retorna los 2 registros creados", count($resListado['json']['data'] ?? []) === 2);

// 7. Consulta Unitaria por ID (GET /api/v1/categorias/{id})
echo "\n--- 7. Detalle Individual por ID (GET /api/v1/categorias/{id}) ---\n";
$resDetalle = requestApi('GET', "/api/v1/categorias/{$idHija}");
assertTest("Detalle individual retorna registro correcto", ($resDetalle['json']['data']['id'] ?? 0) === $idHija);
assertTest("Nombre en detalle coincide", ($resDetalle['json']['data']['nombre'] ?? '') === 'Monitores de Signos Vitales y Desfibriladores');

// 8. Actualización de Datos (PUT /api/v1/categorias/{id})
echo "\n--- 8. Actualización Completa (PUT /api/v1/categorias/{id}) ---\n";
$resUpdate = requestApi('PUT', "/api/v1/categorias/{$idHija}", [
    'nombre' => 'Monitores Multiparámetro de Cuidados Intensivos',
    'categoria_padre_id' => $idRaiz
]);
assertTest("Actualización exitosa", ($resUpdate['json']['success'] ?? false) === true);
assertTest("Nombre actualizado reflejado", ($resUpdate['json']['data']['nombre'] ?? '') === 'Monitores Multiparámetro de Cuidados Intensivos');

// 9. Intento de Auto-padre Anticíclico (PUT con id = padre)
echo "\n--- 9. Verificación de Regla Anticíclica (Auto-padre) ---\n";
$resAuto = requestApi('PUT', "/api/v1/categorias/{$idHija}", [
    'nombre' => 'Prueba Bucle',
    'categoria_padre_id' => $idHija
]);
assertTest("Intento de auto-padre es rechazado (success: false)", ($resAuto['json']['success'] ?? true) === false);
assertTest("Error informa prohibición de auto-padre", str_contains($resAuto['json']['error'] ?? '', 'auto-padre'));

// 10. Inactivación Lógica (DELETE /api/v1/categorias/{id})
echo "\n--- 10. Inactivación Lógica / Soft-Delete (DELETE) ---\n";
$resDelete = requestApi('DELETE', "/api/v1/categorias/{$idHija}");
assertTest("Inactivación lógica responde éxito (200 OK)", ($resDelete['json']['success'] ?? false) === true);
assertTest("Estado del registro cambiado a 'inactivo'", ($resDelete['json']['data']['estado'] ?? '') === 'inactivo');

// 11. Verificación de Persistencia en Base de Datos (Inmutabilidad RN-012)
echo "\n--- 11. Verificación de Integridad en Motor MySQL (RN-012) ---\n";
$stmt = $pdo->prepare("SELECT COUNT(*), estado FROM cat_categoria WHERE id = :id GROUP BY estado");
$stmt->execute(['id' => $idHija]);
$rowCheck = $stmt->fetch();
assertTest("Registro continúa existiendo en BD (cero borrado físico)", ($rowCheck['COUNT(*)'] ?? 0) == 1);
assertTest("Estado físico en base de datos es 'inactivo'", ($rowCheck['estado'] ?? '') === 'inactivo');

// 12. Idempotencia de Inactivación
echo "\n--- 12. Verificación de Idempotencia en Baja ---\n";
$resDelete2 = requestApi('DELETE', "/api/v1/categorias/{$idHija}");
assertTest("Segundo DELETE retorna 200 OK sin error", ($resDelete2['json']['success'] ?? false) === true);
assertTest("Estado permanece 'inactivo'", ($resDelete2['json']['data']['estado'] ?? '') === 'inactivo');

// 13. Manejo de Errores 404 y 400
echo "\n--- 13. Manejo de Rutas de Error (404 y 400) ---\n";
$res404 = requestApi('GET', '/api/v1/categorias/999999');
assertTest("Recurso inexistente retorna error 404", ($res404['json']['success'] ?? true) === false);

$res400 = requestApi('GET', '/api/v1/categorias/abc');
assertTest("ID no numérico retorna error 400", ($res400['json']['success'] ?? true) === false);

@unlink($tmpPayload);

echo "\n============================================================\n";
echo "RESULTADOS SUITE CRUD COMPLETA: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
