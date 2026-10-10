<?php
declare(strict_types=1);

// Test automatizado para CP-BACK-01: Consolidación del patrón reutilizable de backend

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Controllers\Api\CategoriaController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Models\Categoria;
use App\Repositories\CategoriaRepository;
use App\Services\CategoriaService;
use App\Validators\CategoriaValidator;

echo "============================================================\n";
echo "PRUEBAS CP-BACK-01: PATRÓN REUTILIZABLE DE BACKEND (S3IH)\n";
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

$phpBinary = PHP_BINARY;
$runnerScript = __DIR__ . '/runner_test_pattern.php';

// 1. Verificación de Capas Arquitectónicas
echo "--- 1. Existencia y Contratos de las 5 Capas ---\n";
assertTest("Clase Model (Categoria) existe", class_exists(Categoria::class));
assertTest("Clase Repository (CategoriaRepository) existe", class_exists(CategoriaRepository::class));
assertTest("Clase Validator (CategoriaValidator) existe", class_exists(CategoriaValidator::class));
assertTest("Clase Service (CategoriaService) existe", class_exists(CategoriaService::class));
assertTest("Clase Controller (CategoriaController) existe", class_exists(CategoriaController::class));

// 2. Verificación de Comportamiento del Ejecutor Centralizado (Response::execute)
echo "\n--- 2. Verificación de Centralización en Response::execute() ---\n";

// Caso 2.1: Éxito (HTTP 200)
file_put_contents($runnerScript, <<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\Response;
Response::execute(fn() => ['clave' => 'valor_prueba'], 200);
PHP
);
$outOk = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
$jsonOk = json_decode((string)$outOk, true);
assertTest("Response::execute retorna JSON válido en éxito", is_array($jsonOk));
assertTest("success es true en éxito", ($jsonOk['success'] ?? false) === true);
assertTest("data contiene el payload procesado", ($jsonOk['data']['clave'] ?? '') === 'valor_prueba');

// Caso 2.2: Error de Validación / Negocio (RuntimeException 400)
file_put_contents($runnerScript, <<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\Response;
Response::execute(function() {
    throw new RuntimeException("Campo requerido faltante", 400);
}, 200);
PHP
);
$out400 = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
$json400 = json_decode((string)$out400, true);
assertTest("Response::execute traduce RuntimeException(400) a success: false", ($json400['success'] ?? true) === false);
assertTest("Mensaje de validación preservado", ($json400['error'] ?? '') === 'Campo requerido faltante');

// Caso 2.3: Recurso No Encontrado (RuntimeException 404)
file_put_contents($runnerScript, <<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\Response;
Response::execute(function() {
    throw new RuntimeException("Recurso hospitalario no existe", 404);
}, 200);
PHP
);
$out404 = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
$json404 = json_decode((string)$out404, true);
assertTest("Response::execute traduce RuntimeException(404) a error", ($json404['error'] ?? '') === 'Recurso hospitalario no existe');

// Caso 2.4: Falla de Base de Datos (PDOException -> 500 Sanitizado)
file_put_contents($runnerScript, <<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\Response;
Response::execute(function() {
    throw new PDOException("SQLSTATE[HY000] General error: Connection lost to MySQL server at '127.0.0.1'");
}, 200);
PHP
);
$out500 = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
$json500 = json_decode((string)$out500, true);
assertTest("Response::execute captura PDOException y genera success: false", ($json500['success'] ?? true) === false);
assertTest("Mensaje sanitizado ('Error de base de datos')", ($json500['error'] ?? '') === 'Error de base de datos');
assertTest("Sin filtración de detalles SQL internos", !str_contains((string)$out500, 'SQLSTATE') && !str_contains((string)$out500, '127.0.0.1'));

// 3. Verificación de Inmutabilidad Hospitalaria (RN-012)
echo "\n--- 3. Verificación de Inmutabilidad en Repositorio ---\n";
$repoReflection = new ReflectionClass(CategoriaRepository::class);
$hasDeactivate = $repoReflection->hasMethod('deactivate');
assertTest("Repositorio implementa método deactivate()", $hasDeactivate);
assertTest("Repositorio NO implementa método destructivo delete()", !$repoReflection->hasMethod('delete'));

@unlink($runnerScript);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
