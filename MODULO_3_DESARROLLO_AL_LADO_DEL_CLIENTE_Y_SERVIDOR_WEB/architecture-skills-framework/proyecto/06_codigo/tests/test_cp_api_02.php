<?php
declare(strict_types=1);

// Test automatizado para CP-API-02: Bootstrap PHP + .env + PDO + /api/v1/health

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-API-02: BOOTSTRAP + .ENV + PDO + /api/v1/health\n";
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

// 1. Verificación de Bootstrap y .env
echo "--- 1. Verificación de Bootstrap y .env ---\n";
assertTest("Constante de versión PHP >= 8.2", version_compare(PHP_VERSION, '8.2.0', '>='));
assertTest("Función envRequired('APP_NAME') retorna nombre S3IH", str_contains(envRequired('APP_NAME'), 'S3IH'));
assertTest("Variable de BD DB_NAME configurada en s3ih_db", envRequired('DB_NAME') === 's3ih_db');
assertTest("Variable de BD DB_HOST configurada en 127.0.0.1", envRequired('DB_HOST') === '127.0.0.1');

// 2. Verificación de PDO y Database::ping()
echo "\n--- 2. Verificación de Conectividad PDO ---\n";
try {
    $pdo = Database::conn();
    assertTest("Database::conn() retorna instancia PDO válida", $pdo instanceof PDO);
    assertTest("Database::ping() retorna true con servidor MySQL activo", Database::ping() === true);
} catch (Throwable $e) {
    assertTest("Database::conn() falló con excepción", false, $e->getMessage());
}

// 3. Verificación de Endpoint /api/v1/health (Happy Path: 200 OK)
echo "\n--- 3. Verificación de Endpoint /api/v1/health (Saludable) ---\n";
$phpBinary = PHP_BINARY;

$runnerScript = __DIR__ . '/run_request.php';
file_put_contents($runnerScript, <<<'PHP'
<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/v1/health';
require dirname(__DIR__) . '/public/index.php';
PHP
);

$outputHappy = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
$jsonHappy = json_decode((string)$outputHappy, true);

assertTest("Endpoint /api/v1/health responde JSON válido", is_array($jsonHappy));
assertTest("Respuesta contiene success: true", ($jsonHappy['success'] ?? false) === true);
assertTest("data.status es 'ok'", ($jsonHappy['data']['status'] ?? '') === 'ok');
assertTest("data.db es 'up'", ($jsonHappy['data']['db'] ?? '') === 'up');
assertTest("data.php informa versión PHP", isset($jsonHappy['data']['php']));
assertTest("data.timestamp presente en formato ISO", isset($jsonHappy['data']['timestamp']));

// 4. Verificación de Endpoint /api/v1/health ante Falla DB (Simulación 500)
echo "\n--- 4. Verificación de Manejo de Error en /api/v1/health (Fallo DB) ---\n";

file_put_contents($runnerScript, <<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;

// Inyectar conexión caída
$brokenPdo = new class extends PDO {
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): false {
        throw new PDOException("Simulated DB connection lost");
    }
};
Database::setConnection($brokenPdo);

$router = new Router();
foreach (require dirname(__DIR__) . '/routes/api.php' as [$method, $pattern, $handler]) {
    $router->add($method, $pattern, $handler);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/v1/health';
$router->dispatch(new Request());
PHP
);

$outputFail = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
$jsonFail = json_decode((string)$outputFail, true);

assertTest("Endpoint ante fallo responde JSON válido", is_array($jsonFail));
assertTest("Respuesta contiene success: false", ($jsonFail['success'] ?? true) === false);
assertTest("Mensaje de error sanitizado ('Error de base de datos')", ($jsonFail['error'] ?? '') === 'Error de base de datos');

@unlink($runnerScript);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
