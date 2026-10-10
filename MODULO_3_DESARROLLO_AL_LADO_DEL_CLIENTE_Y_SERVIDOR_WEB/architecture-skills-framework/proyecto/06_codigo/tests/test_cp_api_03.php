<?php
declare(strict_types=1);

// Test automatizado para CP-API-03: GET /api/v1/categorias (colección con datos, vacía y error DB)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-API-03: GET /api/v1/categorias\n";
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
$runnerScript = __DIR__ . '/runner_categorias.php';

// Limpieza controlada de fixture respetando jerarquía
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE cat_categoria");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// CASO 1: Colección Vacía
echo "--- 1. Prueba de Colección Vacía (data: []) ---\n";

file_put_contents($runnerScript, <<<'PHP'
<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/v1/categorias';
require dirname(__DIR__) . '/public/index.php';
PHP
);

$outputEmpty = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
$jsonEmpty = json_decode((string)$outputEmpty, true);

assertTest("Respuesta es JSON válido en colección vacía", is_array($jsonEmpty));
assertTest("success es true en colección vacía", ($jsonEmpty['success'] ?? false) === true);
assertTest("data es un array vacío []", is_array($jsonEmpty['data'] ?? null) && count($jsonEmpty['data']) === 0);

// CASO 2: Colección con Datos
echo "\n--- 2. Prueba de Colección con Datos ---\n";
$pdo->exec("
    INSERT INTO cat_categoria (id, nombre, categoria_padre_id, estado, user_create, user_update)
    VALUES 
    (1, 'Equipamiento Biomédico y Soporte Vital', NULL, 'activo', 1, 1),
    (2, 'Ventiladores Mecánicos y Oxigenoterapia', 1, 'activo', 1, 1),
    (3, 'Infraestructura y Redes Eléctricas', NULL, 'activo', 1, 1)
");

$outputData = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
$jsonData = json_decode((string)$outputData, true);

assertTest("Respuesta es JSON válido en colección con datos", is_array($jsonData));
assertTest("success es true en colección con datos", ($jsonData['success'] ?? false) === true);
assertTest("data contiene 3 elementos", is_array($jsonData['data'] ?? null) && count($jsonData['data']) === 3);
assertTest("Elemento 1 tiene id 1 y nombre correcto", ($jsonData['data'][0]['id'] ?? 0) === 1 && ($jsonData['data'][0]['nombre'] ?? '') === 'Equipamiento Biomédico y Soporte Vital');
assertTest("Elemento 2 tiene categoria_padre_id = 1", ($jsonData['data'][1]['categoria_padre_id'] ?? 0) === 1);
assertTest("Todos los elementos exponen estado", isset($jsonData['data'][0]['estado']) && $jsonData['data'][0]['estado'] === 'activo');

// CASO 3: Error de Base de Datos (500 sin stack trace)
echo "\n--- 3. Prueba de Error de Base de Datos (HTTP 500) ---\n";

file_put_contents($runnerScript, <<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;

// Simular falla fatal en conexión a base de datos
$brokenPdo = new class extends PDO {
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): false {
        throw new PDOException("Simulated connection timeout to MySQL host");
    }
};
Database::setConnection($brokenPdo);

$router = new Router();
foreach (require dirname(__DIR__) . '/routes/api.php' as [$method, $pattern, $handler]) {
    $router->add($method, $pattern, $handler);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/v1/categorias';
$router->dispatch(new Request());
PHP
);

$outputError = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
$jsonError = json_decode((string)$outputError, true);

assertTest("Respuesta ante error es JSON válido", is_array($jsonError));
assertTest("success es false ante error DB", ($jsonError['success'] ?? true) === false);
assertTest("Mensaje de error sanitizado ('Error de base de datos')", ($jsonError['error'] ?? '') === 'Error de base de datos');
assertTest("Sin stack trace ni filtración de contraseñas", !str_contains((string)$outputError, 'Stack trace') && !str_contains((string)$outputError, 'PDOException'));

@unlink($runnerScript);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
