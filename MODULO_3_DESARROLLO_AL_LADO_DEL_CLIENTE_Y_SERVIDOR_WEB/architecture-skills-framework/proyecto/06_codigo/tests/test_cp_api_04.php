<?php
declare(strict_types=1);

// Test automatizado para CP-API-04: GET /api/v1/categorias/{id} (existente, inexistente e ID inválido)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-API-04: GET /api/v1/categorias/{id}\n";
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
$runnerScript = __DIR__ . '/runner_categoria_id.php';

// Preparar fixture con categoría conocida
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE cat_categoria");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

$pdo->exec("
    INSERT INTO cat_categoria (id, nombre, categoria_padre_id, estado, user_create, user_update)
    VALUES 
    (10, 'Monitores Multiparámetro UCI', NULL, 'activo', 1, 1),
    (20, 'Sensores de Oximetría Pediátrica', 10, 'activo', 1, 1)
");

function execEndpoint(string $path): array
{
    global $phpBinary, $runnerScript;
    $code = sprintf(<<<'PHP'
<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '%s';
require dirname(__DIR__) . '/public/index.php';
PHP
    , $path);

    file_put_contents($runnerScript, $code);
    $output = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
    $json = json_decode((string)$output, true);
    return ['output' => (string)$output, 'json' => $json];
}

// 1. CASO EXISTENTE: GET /api/v1/categorias/10 y /api/v1/categorias/20
echo "--- 1. Prueba de Categoría Existente (HTTP 200 OK) ---\n";
$resExistente = execEndpoint('/api/v1/categorias/10');
assertTest("Respuesta es JSON válido para ID existente", is_array($resExistente['json']));
assertTest("success es true para ID 10", ($resExistente['json']['success'] ?? false) === true);
assertTest("data.id es 10", ($resExistente['json']['data']['id'] ?? 0) === 10);
assertTest("data.nombre es 'Monitores Multiparámetro UCI'", ($resExistente['json']['data']['nombre'] ?? '') === 'Monitores Multiparámetro UCI');
assertTest("data.categoria_padre_id es null para categoría raíz", ($resExistente['json']['data']['categoria_padre_id'] ?? null) === null);
assertTest("data.estado es 'activo'", ($resExistente['json']['data']['estado'] ?? '') === 'activo');

$resHijo = execEndpoint('/api/v1/categorias/20');
assertTest("data.categoria_padre_id es 10 para categoría hija", ($resHijo['json']['data']['categoria_padre_id'] ?? 0) === 10);

// 2. CASO INEXISTENTE: GET /api/v1/categorias/999999
echo "\n--- 2. Prueba de Categoría Inexistente (HTTP 404 Not Found) ---\n";
$resInexistente = execEndpoint('/api/v1/categorias/999999');
assertTest("Respuesta es JSON válido para ID inexistente", is_array($resInexistente['json']));
assertTest("success es false para ID inexistente", ($resInexistente['json']['success'] ?? true) === false);
assertTest("error contiene 'Categoría no encontrada'", ($resInexistente['json']['error'] ?? '') === 'Categoría no encontrada');

// 3. CASO ID INVÁLIDO: Alfanumérico, negativo, cero
echo "\n--- 3. Prueba de ID Inválido (HTTP 400 Bad Request) ---\n";
$resAlpha = execEndpoint('/api/v1/categorias/abc');
assertTest("ID alfanumérico ('abc') retorna success: false", ($resAlpha['json']['success'] ?? true) === false);
assertTest("ID alfanumérico informa error 'ID inválido'", ($resAlpha['json']['error'] ?? '') === 'ID inválido');

$resZero = execEndpoint('/api/v1/categorias/0');
assertTest("ID cero ('0') retorna error 'ID inválido'", ($resZero['json']['error'] ?? '') === 'ID inválido');

$resNegative = execEndpoint('/api/v1/categorias/-5');
assertTest("ID negativo ('-5') retorna error 'ID inválido'", ($resNegative['json']['error'] ?? '') === 'ID inválido');

@unlink($runnerScript);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
