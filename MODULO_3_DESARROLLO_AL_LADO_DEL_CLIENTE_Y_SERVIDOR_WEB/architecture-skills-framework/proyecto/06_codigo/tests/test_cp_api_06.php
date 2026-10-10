<?php
declare(strict_types=1);

// Test automatizado para CP-API-06: PUT /api/v1/categorias/{id} (validaciones, auto-padre, 404 y 200)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-API-06: PUT /api/v1/categorias/{id}\n";
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
$tmpPayload = __DIR__ . '/tmp_payload_put.json';

// Preparar fixture inicial
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE cat_categoria");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

$pdo->exec("
    INSERT INTO cat_categoria (id, nombre, categoria_padre_id, estado, user_create, user_update)
    VALUES 
    (100, 'Infraestructura General', NULL, 'activo', 1, 1),
    (200, 'Redes de Gases Medicinales', 100, 'activo', 1, 1),
    (300, 'Sistemas de Climatización Limpia', 100, 'activo', 1, 1)
");

function callPut(string $uri, string $payload, string $contentType = 'application/json'): array
{
    global $phpBinary, $runnerScript, $tmpPayload;
    file_put_contents($tmpPayload, $payload);
    $cmd = "\"{$phpBinary}\" \"{$runnerScript}\" PUT {$uri} \"{$tmpPayload}\" \"{$contentType}\" 2>&1";
    $output = shell_exec($cmd);
    $json = json_decode((string)$output, true);
    return ['output' => (string)$output, 'json' => $json];
}

// 1. CASOS VÁLIDOS (HTTP 200 OK)
echo "--- 1. Pruebas de Actualización Exitosa (HTTP 200 OK) ---\n";

// Caso 1.1: Actualizar nombre manteniendo padre null
$payloadPut1 = json_encode([
    'nombre' => 'Infraestructura Hospitalaria y Obras',
    'categoria_padre_id' => null
]);
$resPut1 = callPut('/api/v1/categorias/100', $payloadPut1);

assertTest("PUT retorna JSON válido", is_array($resPut1['json']));
assertTest("success es true en actualización exitosa", ($resPut1['json']['success'] ?? false) === true);
assertTest("Nombre actualizado reflejado", ($resPut1['json']['data']['nombre'] ?? '') === 'Infraestructura Hospitalaria y Obras');
assertTest("ID se mantiene en 100", ($resPut1['json']['data']['id'] ?? 0) === 100);
assertTest("categoria_padre_id permanece null", ($resPut1['json']['data']['categoria_padre_id'] ?? null) === null);
assertTest("Estado permanece activo", ($resPut1['json']['data']['estado'] ?? '') === 'activo');

// Caso 1.2: Reasignar categoría hija a otro padre existente
$payloadPut2 = json_encode([
    'nombre' => 'Ductos de Oxígeno Criogénico',
    'categoria_padre_id' => 300
]);
$resPut2 = callPut('/api/v1/categorias/200', $payloadPut2);

assertTest("Reasignación de padre retorna success: true", ($resPut2['json']['success'] ?? false) === true);
assertTest("categoria_padre_id actualizado a 300", ($resPut2['json']['data']['categoria_padre_id'] ?? 0) === 300);
assertTest("Nombre actualizado a 'Ductos de Oxígeno Criogénico'", ($resPut2['json']['data']['nombre'] ?? '') === 'Ductos de Oxígeno Criogénico');

// 2. VALIDACIONES DE REGLAS DE NEGOCIO (HTTP 400 Bad Request)
echo "\n--- 2. Validaciones de Negocio y Formato (HTTP 400 Bad Request) ---\n";

// Caso 2.1: Auto-padre (categoria_padre_id == id)
$payloadAutoPadre = json_encode([
    'nombre' => 'Infraestructura Circular',
    'categoria_padre_id' => 100
]);
$resAutoPadre = callPut('/api/v1/categorias/100', $payloadAutoPadre);

assertTest("Auto-padre retorna success: false", ($resAutoPadre['json']['success'] ?? true) === false);
assertTest("Auto-padre informa regla anticíclica", str_contains($resAutoPadre['json']['error'] ?? '', 'auto-padre'));

// Caso 2.2: Nombre vacío
$payloadNombreVacio = json_encode([
    'nombre' => '   ',
    'categoria_padre_id' => null
]);
$resNombreVacio = callPut('/api/v1/categorias/100', $payloadNombreVacio);
assertTest("Nombre vacío retorna success: false", ($resNombreVacio['json']['success'] ?? true) === false);
assertTest("Nombre vacío informa regla de validación", str_contains($resNombreVacio['json']['error'] ?? '', 'nombre: obligatorio'));

// Caso 2.3: Nombre mayor a 100 caracteres
$payloadNombreLargo = json_encode([
    'nombre' => str_repeat('X', 101),
    'categoria_padre_id' => null
]);
$resNombreLargo = callPut('/api/v1/categorias/100', $payloadNombreLargo);
assertTest("Nombre > 100 caracteres retorna success: false", ($resNombreLargo['json']['success'] ?? true) === false);
assertTest("Nombre largo informa máximo caracteres", str_contains($resNombreLargo['json']['error'] ?? '', 'máximo 100'));

// Caso 2.4: ID de path inválido (no numérico)
$resIdInvalido = callPut('/api/v1/categorias/invalid-id', $payloadPut1);
assertTest("ID no numérico en URL retorna success: false", ($resIdInvalido['json']['success'] ?? true) === false);
assertTest("ID inválido informa regla", str_contains($resIdInvalido['json']['error'] ?? '', 'ID inválido'));

// 3. CASOS DE INEXISTENCIA (HTTP 404 Not Found)
echo "\n--- 3. Casos de Inexistencia (HTTP 404 Not Found) ---\n";

// Caso 3.1: Categoría principal no existe
$resCatInexistente = callPut('/api/v1/categorias/999999', $payloadPut1);
assertTest("Categoría principal inexistente retorna success: false", ($resCatInexistente['json']['success'] ?? true) === false);
assertTest("Informa 'Categoría no encontrada'", str_contains($resCatInexistente['json']['error'] ?? '', 'Categoría no encontrada'));

// Caso 3.2: Padre referenciado no existe
$payloadPadreInexistente = json_encode([
    'nombre' => 'Sistemas de Aspiración Central',
    'categoria_padre_id' => 888888
]);
$resPadreInexistente = callPut('/api/v1/categorias/100', $payloadPadreInexistente);
assertTest("categoria_padre_id inexistente retorna success: false", ($resPadreInexistente['json']['success'] ?? true) === false);
assertTest("Informa 'categoria_padre_id no existe'", str_contains($resPadreInexistente['json']['error'] ?? '', 'categoria_padre_id no existe'));

@unlink($tmpPayload);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
