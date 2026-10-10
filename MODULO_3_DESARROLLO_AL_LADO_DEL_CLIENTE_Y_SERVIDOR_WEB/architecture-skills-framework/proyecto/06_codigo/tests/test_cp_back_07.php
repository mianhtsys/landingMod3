<?php
declare(strict_types=1);

// Test automatizado para CP-BACK-07: PUT productos (actualización, unicidad de SKU, 404, 409 y 401)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-BACK-07: PUT PRODUCTOS (S3IH)\n";
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
$runnerScript = __DIR__ . '/runner_put_producto.php';

// Limpieza y preparación de fixtures
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE auth_usuario");
$pdo->exec("TRUNCATE TABLE cat_producto");
$pdo->exec("TRUNCATE TABLE cat_categoria");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// Crear usuario demo
$emailDemo = envRequired('DEMO_USER_EMAIL');
$passDemo = envRequired('DEMO_USER_PASSWORD');
$hashDemo = password_hash($passDemo, PASSWORD_ARGON2ID);
$stmtUser = $pdo->prepare("
    INSERT INTO auth_usuario (id, nombre, email, hash_password, estado)
    VALUES (1, 'Supervisor Biomédica H1', :email, :hash, 'activo')
");
$stmtUser->execute(['email' => $emailDemo, 'hash' => $hashDemo]);

// Crear categorías activas
$pdo->exec("
    INSERT INTO cat_categoria (id, nombre, categoria_padre_id, estado)
    VALUES 
    (10, 'Equipamiento Biomédico', NULL, 'activo'),
    (20, 'Gases Clínicos y Soporte', NULL, 'activo')
");

// Insertar 2 productos para pruebas cruzadas
$pdo->exec("
    INSERT INTO cat_producto (id, sku, nombre, categoria_id, unidad, estado)
    VALUES 
    (1, 'BIO-DEF-001', 'Desfibrilador Manual y DEA', 10, 'UNIDAD', 'activo'),
    (2, 'GAS-MAN-002', 'Manómetro de Oxígeno Alta Presión', 20, 'UNIDAD', 'activo')
");

// Helper para obtener sessionId activo
function getAuthSessionId(): string
{
    global $phpBinary, $emailDemo, $passDemo;
    $loginRunner = __DIR__ . '/runner_temp_login_put.php';
    file_put_contents($loginRunner, sprintf(<<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\Request;
use App\Core\Router;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/v1/auth/login';
$_SERVER['CONTENT_TYPE'] = 'application/json';
Request::$testInput = json_encode(['email' => '%s', 'password' => '%s']);
$router = new Router();
foreach (require dirname(__DIR__) . '/routes/api.php' as [$m, $pattern, $handler]) {
    $router->add($m, $pattern, $handler);
}
register_shutdown_function(function() {
    file_put_contents(__DIR__ . '/active_sess_put.txt', session_id());
});
$router->dispatch(new Request());
PHP
    , $emailDemo, $passDemo));

    shell_exec("\"{$phpBinary}\" \"{$loginRunner}\" 2>&1");
    @unlink($loginRunner);
    $sessId = trim((string)file_get_contents(__DIR__ . '/active_sess_put.txt'));
    @unlink(__DIR__ . '/active_sess_put.txt');
    return $sessId;
}

$sessionId = getAuthSessionId();

function callPutProduct(string $uri, string $payloadJson, ?string $sess = null): array
{
    global $phpBinary, $runnerScript;
    $payloadFile = __DIR__ . '/tmp_payload_product_put.json';
    file_put_contents($payloadFile, $payloadJson);

    $code = sprintf(<<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\Request;
use App\Core\Router;

$_SERVER['REQUEST_METHOD'] = 'PUT';
$_SERVER['REQUEST_URI'] = '%s';
$_SERVER['CONTENT_TYPE'] = 'application/json';

$sess = '%s';
if ($sess !== '') {
    $_COOKIE['s3ih_session'] = $sess;
}

$raw = file_get_contents('%s');
Request::$testInput = $raw;

$router = new Router();
foreach (require dirname(__DIR__) . '/routes/api.php' as [$m, $pattern, $handler]) {
    $router->add($m, $pattern, $handler);
}
$router->dispatch(new Request());
PHP
    , $uri, $sess ?? '', addcslashes($payloadFile, "'\\"));

    file_put_contents($runnerScript, $code);
    $output = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
    @unlink($payloadFile);
    $json = json_decode((string)$output, true);
    return ['output' => (string)$output, 'json' => $json];
}

// 1. ACTUALIZACIÓN EXITOSA (HTTP 200 OK)
echo "--- 1. Actualización Exitosa (HTTP 200 OK) ---\n";

// Caso 1.1: Modificar nombre y unidad manteniendo su propio SKU
$payloadUpdate1 = json_encode([
    'sku' => 'BIO-DEF-001',
    'nombre' => 'Desfibrilador Bifásico Avanzado UCI',
    'categoria_id' => 10,
    'unidad' => 'EQUIPO'
]);
$resOk1 = callPutProduct('/api/v1/productos/1', $payloadUpdate1, $sessionId);

assertTest("Actualización exitosa responde JSON válido", is_array($resOk1['json']));
assertTest("success es true en 200 OK", ($resOk1['json']['success'] ?? false) === true);
assertTest("Nombre actualizado reflejado", ($resOk1['json']['data']['nombre'] ?? '') === 'Desfibrilador Bifásico Avanzado UCI');
assertTest("Unidad actualizada a EQUIPO", ($resOk1['json']['data']['unidad'] ?? '') === 'EQUIPO');
assertTest("SKU se mantiene idéntico sin colisionar consigo mismo", ($resOk1['json']['data']['sku'] ?? '') === 'BIO-DEF-001');

// Caso 1.2: Cambiar SKU a uno nuevo no utilizado y cambiar categoría
$payloadUpdate2 = json_encode([
    'sku' => 'BIO-DEF-999',
    'nombre' => 'Desfibrilador Portátil Ambulancia',
    'categoria_id' => 20,
    'unidad' => 'EQUIPO'
]);
$resOk2 = callPutProduct('/api/v1/productos/1', $payloadUpdate2, $sessionId);

assertTest("Modificación de SKU nuevo responde success: true", ($resOk2['json']['success'] ?? false) === true);
assertTest("Nuevo SKU reflejado", ($resOk2['json']['data']['sku'] ?? '') === 'BIO-DEF-999');
assertTest("Nueva categoria_id reflejada", ($resOk2['json']['data']['categoria_id'] ?? 0) === 20);

// 2. CONFLICTO DE UNICIDAD SKU (HTTP 409 Conflict)
echo "\n--- 2. Conflicto de Unicidad de SKU (HTTP 409 Conflict) ---\n";
// Intentar actualizar el producto 1 con el SKU del producto 2 ('GAS-MAN-002')
$payloadConflicto = json_encode([
    'sku' => 'GAS-MAN-002',
    'nombre' => 'Intento de Duplicar SKU',
    'categoria_id' => 10,
    'unidad' => 'UNIDAD'
]);
$resConflicto = callPutProduct('/api/v1/productos/1', $payloadConflicto, $sessionId);

assertTest("SKU duplicado con otro producto retorna success: false", ($resConflicto['json']['success'] ?? true) === false);
assertTest("Informa error 409 'sku ya existe'", str_contains($resConflicto['json']['error'] ?? '', 'sku ya existe'));

// 3. CASOS DE INEXISTENCIA (HTTP 404 Not Found)
echo "\n--- 3. Casos de Inexistencia (HTTP 404 Not Found) ---\n";

// Caso 3.1: Producto inexistente
$resProdInexistente = callPutProduct('/api/v1/productos/999999', $payloadUpdate1, $sessionId);
assertTest("Producto inexistente retorna error 404", str_contains($resProdInexistente['json']['error'] ?? '', 'Producto no encontrado'));

// Caso 3.2: categoria_id inexistente
$payloadCatInexistente = json_encode([
    'sku' => 'BIO-DEF-001',
    'nombre' => 'Desfibrilador Clínico',
    'categoria_id' => 888888,
    'unidad' => 'UNIDAD'
]);
$resCatInexistente = callPutProduct('/api/v1/productos/1', $payloadCatInexistente, $sessionId);
assertTest("categoria_id inexistente retorna error 404", str_contains($resCatInexistente['json']['error'] ?? '', 'categoria_id no existe'));

// 4. VALIDACIONES DE FORMATO Y ID (HTTP 400 Bad Request)
echo "\n--- 4. Validaciones de Formato y ID (HTTP 400 Bad Request) ---\n";
$resIdInvalido = callPutProduct('/api/v1/productos/no-valido', $payloadUpdate1, $sessionId);
assertTest("ID no numérico en URI retorna error 400", str_contains($resIdInvalido['json']['error'] ?? '', 'ID inválido'));

$payloadNombreVacio = json_encode([
    'sku' => 'BIO-DEF-001',
    'nombre' => '   ',
    'categoria_id' => 10,
    'unidad' => 'UNIDAD'
]);
$resNombreVacio = callPutProduct('/api/v1/productos/1', $payloadNombreVacio, $sessionId);
assertTest("Nombre vacío retorna error 400", str_contains($resNombreVacio['json']['error'] ?? '', 'nombre: obligatorio'));

// 5. PROTECCIÓN DE SESIÓN (HTTP 401 Unauthorized)
echo "\n--- 5. Protección de Sesión (HTTP 401 Unauthorized) ---\n";
$resSinSesion = callPutProduct('/api/v1/productos/1', $payloadUpdate1, null);
assertTest("Llamada sin sesión retorna error 401", ($resSinSesion['json']['error'] ?? '') === 'No autenticado');

@unlink($runnerScript);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
