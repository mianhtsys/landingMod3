<?php
declare(strict_types=1);

// Test automatizado para CP-API-05: POST /api/v1/categorias (fixtures válidos e inválidos)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-API-05: POST /api/v1/categorias\n";
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
$tmpPayload = __DIR__ . '/tmp_payload.json';

// Limpiar tabla para pruebas limpias de POST
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE cat_categoria");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

function callPost(string $payload, string $contentType = 'application/json'): array
{
    global $phpBinary, $runnerScript, $tmpPayload;
    file_put_contents($tmpPayload, $payload);
    $cmd = "\"{$phpBinary}\" \"{$runnerScript}\" POST /api/v1/categorias \"{$tmpPayload}\" \"{$contentType}\" 2>&1";
    $output = shell_exec($cmd);
    $json = json_decode((string)$output, true);
    return ['output' => (string)$output, 'json' => $json];
}

// 1. FIXTURES VÁLIDOS (HTTP 201 Created)
echo "--- 1. Fixtures Válidos (Creación exitosa 201) ---\n";

// Caso 1.1: Categoría Raíz Válida
$payloadValido1 = json_encode([
    'nombre' => 'Equipamiento Biomédico y Soporte Vital',
    'categoria_padre_id' => null
]);
$resValido1 = callPost($payloadValido1);

assertTest("Categoría raíz retorna JSON válido", is_array($resValido1['json']));
assertTest("Categoría raíz retorna success: true", ($resValido1['json']['success'] ?? false) === true);
$idCreado1 = $resValido1['json']['data']['id'] ?? 0;
assertTest("ID generado es entero positivo", is_int($idCreado1) && $idCreado1 > 0);
assertTest("Nombre guardado coincide", ($resValido1['json']['data']['nombre'] ?? '') === 'Equipamiento Biomédico y Soporte Vital');
assertTest("categoria_padre_id es null", ($resValido1['json']['data']['categoria_padre_id'] ?? null) === null);
assertTest("Estado inicial es 'activo'", ($resValido1['json']['data']['estado'] ?? '') === 'activo');

// Caso 1.2: Subcategoría Válida con Padre Existente
$payloadValido2 = json_encode([
    'nombre' => 'Ventiladores Mecánicos de Alta Frecuencia',
    'categoria_padre_id' => $idCreado1
]);
$resValido2 = callPost($payloadValido2);

assertTest("Subcategoría retorna success: true", ($resValido2['json']['success'] ?? false) === true);
$idCreado2 = $resValido2['json']['data']['id'] ?? 0;
assertTest("Subcategoría tiene ID distinto al padre", $idCreado2 > $idCreado1);
assertTest("Subcategoría referencia correctamente al padre", ($resValido2['json']['data']['categoria_padre_id'] ?? 0) === $idCreado1);

// 2. FIXTURES INVÁLIDOS (HTTP 400 Bad Request)
echo "\n--- 2. Fixtures Inválidos (Errores de validación 400) ---\n";

// Caso 2.1: Nombre ausente
$payloadSinNombre = json_encode(['categoria_padre_id' => null]);
$resSinNombre = callPost($payloadSinNombre);
assertTest("Nombre ausente retorna success: false", ($resSinNombre['json']['success'] ?? true) === false);
assertTest("Nombre ausente informa regla de validación", str_contains($resSinNombre['json']['error'] ?? '', 'nombre: obligatorio'));

// Caso 2.2: Nombre vacío
$payloadNombreVacio = json_encode(['nombre' => '   ', 'categoria_padre_id' => null]);
$resNombreVacio = callPost($payloadNombreVacio);
assertTest("Nombre vacío retorna success: false", ($resNombreVacio['json']['success'] ?? true) === false);
assertTest("Nombre vacío informa regla de validación", str_contains($resNombreVacio['json']['error'] ?? '', 'nombre: obligatorio'));

// Caso 2.3: Nombre excesivamente largo (> 100 caracteres)
$payloadNombreLargo = json_encode([
    'nombre' => str_repeat('A', 105),
    'categoria_padre_id' => null
]);
$resNombreLargo = callPost($payloadNombreLargo);
assertTest("Nombre > 100 caracteres retorna success: false", ($resNombreLargo['json']['success'] ?? true) === false);
assertTest("Nombre largo informa máximo caracteres", str_contains($resNombreLargo['json']['error'] ?? '', 'máximo 100 caracteres'));

// Caso 2.4: categoria_padre_id con tipo inválido (negativo)
$payloadPadreInvalido = json_encode([
    'nombre' => 'Suministros de Laboratorio',
    'categoria_padre_id' => -1
]);
$resPadreInvalido = callPost($payloadPadreInvalido);
assertTest("categoria_padre_id negativo retorna success: false", ($resPadreInvalido['json']['success'] ?? true) === false);
assertTest("categoria_padre_id inválido informa regla", str_contains($resPadreInvalido['json']['error'] ?? '', 'categoria_padre_id: entero mayor 0'));

// Caso 2.5: Cuerpo JSON totalmente malformado
$resMalformado = callPost('{invalid-json-content}');
assertTest("JSON malformado retorna success: false", ($resMalformado['json']['success'] ?? true) === false);
assertTest("JSON malformado informa cuerpo inválido", str_contains($resMalformado['json']['error'] ?? '', 'Cuerpo JSON inválido'));

// 3. FIXTURE DE PADRE INEXISTENTE (HTTP 404 Not Found)
echo "\n--- 3. Fixture de Padre Inexistente (HTTP 404 Not Found) ---\n";
$payloadPadreInexistente = json_encode([
    'nombre' => 'Gases Clínicos y Criogenia',
    'categoria_padre_id' => 999999
]);
$resPadreInexistente = callPost($payloadPadreInexistente);
assertTest("Padre inexistente retorna success: false", ($resPadreInexistente['json']['success'] ?? true) === false);
assertTest("Padre inexistente informa que no existe (404)", str_contains($resPadreInexistente['json']['error'] ?? '', 'categoria_padre_id no existe'));

@unlink($tmpPayload);
@unlink(__DIR__ . '/runner_post_categoria.php');

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
