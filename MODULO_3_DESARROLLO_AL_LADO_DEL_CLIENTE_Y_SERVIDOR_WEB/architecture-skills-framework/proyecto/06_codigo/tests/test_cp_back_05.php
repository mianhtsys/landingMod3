<?php
declare(strict_types=1);

// Test automatizado para CP-BACK-05: GET productos/listado-detalle

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-BACK-05: GET PRODUCTOS (LISTADO Y DETALLE) S3IH\n";
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
$runnerScript = __DIR__ . '/runner_productos_get.php';

// Preparar usuario demo y datos de prueba
$emailDemo = envRequired('DEMO_USER_EMAIL');
$passDemo = envRequired('DEMO_USER_PASSWORD');
$hashDemo = password_hash($passDemo, PASSWORD_ARGON2ID);

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE auth_usuario");
$pdo->exec("TRUNCATE TABLE cat_producto");
$pdo->exec("TRUNCATE TABLE cat_categoria");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// Crear usuario demo
$stmtUser = $pdo->prepare("
    INSERT INTO auth_usuario (id, nombre, email, hash_password, estado)
    VALUES (1, 'Supervisor Biomédica H1', :email, :hash, 'activo')
");
$stmtUser->execute(['email' => $emailDemo, 'hash' => $hashDemo]);

// Crear categorías de soporte para productos hospitalarios
$pdo->exec("
    INSERT INTO cat_categoria (id, nombre, categoria_padre_id, estado)
    VALUES 
    (10, 'Equipamiento Biomédico y Soporte Vital', NULL, 'activo'),
    (20, 'Gases Medicinales y Criogenia', NULL, 'activo')
");

// Helper para login y obtención de sesión activa
function getAuthSessionId(): string
{
    global $phpBinary, $emailDemo, $passDemo;
    $loginRunner = __DIR__ . '/runner_temp_login.php';
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
    file_put_contents(__DIR__ . '/active_sess.txt', session_id());
});
$router->dispatch(new Request());
PHP
    , $emailDemo, $passDemo));

    shell_exec("\"{$phpBinary}\" \"{$loginRunner}\" 2>&1");
    @unlink($loginRunner);
    $sessId = trim((string)file_get_contents(__DIR__ . '/active_sess.txt'));
    @unlink(__DIR__ . '/active_sess.txt');
    return $sessId;
}

$sessionId = getAuthSessionId();

// Helper para peticiones a la API
function callGetProduct(string $uri, ?string $sess = null): array
{
    global $phpBinary, $runnerScript;
    $code = sprintf(<<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\Request;
use App\Core\Router;

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '%s';
$_SERVER['CONTENT_TYPE'] = 'application/json';

$sess = '%s';
if ($sess !== '') {
    $_COOKIE['s3ih_session'] = $sess;
}

$router = new Router();
foreach (require dirname(__DIR__) . '/routes/api.php' as [$m, $pattern, $handler]) {
    $router->add($m, $pattern, $handler);
}
$router->dispatch(new Request());
PHP
    , $uri, $sess ?? '');

    file_put_contents($runnerScript, $code);
    $output = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
    $json = json_decode((string)$output, true);
    return ['output' => (string)$output, 'json' => $json];
}

// 1. CASO COLECCIÓN VACÍA (GET /api/v1/productos)
echo "--- 1. Listado con Colección Vacía (GET /productos) ---\n";
$resVacio = callGetProduct('/api/v1/productos', $sessionId);
assertTest("Colección vacía responde JSON válido", is_array($resVacio['json']));
assertTest("success es true en colección vacía", ($resVacio['json']['success'] ?? false) === true);
assertTest("data es un array vacío []", is_array($resVacio['json']['data'] ?? null) && count($resVacio['json']['data']) === 0);

// 2. CASO COLECCIÓN CON DATOS
echo "\n--- 2. Listado con Datos Hospitalarios (GET /productos) ---\n";
$pdo->exec("
    INSERT INTO cat_producto (id, sku, nombre, categoria_id, unidad, estado)
    VALUES 
    (1, 'BIO-MON-001', 'Monitor de Signos Vitales 5 Parámetros', 10, 'UNIDAD', 'activo'),
    (2, 'GAS-OXI-050', 'Cilindro de Oxígeno Medicinal 50L', 20, 'CILINDRO', 'activo')
");

$resData = callGetProduct('/api/v1/productos', $sessionId);
assertTest("Colección con datos responde success: true", ($resData['json']['success'] ?? false) === true);
assertTest("data contiene 2 productos", count($resData['json']['data'] ?? []) === 2);
assertTest("Producto 1 tiene SKU 'BIO-MON-001'", ($resData['json']['data'][0]['sku'] ?? '') === 'BIO-MON-001');
assertTest("Producto 2 tiene categoria_id = 20", ($resData['json']['data'][1]['categoria_id'] ?? 0) === 20);
assertTest("Exponen unidad de medida y estado", ($resData['json']['data'][0]['unidad'] ?? '') === 'UNIDAD' && ($resData['json']['data'][0]['estado'] ?? '') === 'activo');

// 3. CASO DETALLE INDIVIDUAL (GET /api/v1/productos/{id})
echo "\n--- 3. Detalle Individual por ID (GET /productos/{id}) ---\n";
$resDetalle = callGetProduct('/api/v1/productos/1', $sessionId);
assertTest("Detalle individual responde success: true", ($resDetalle['json']['success'] ?? false) === true);
assertTest("data.id es 1", ($resDetalle['json']['data']['id'] ?? 0) === 1);
assertTest("data.nombre coincide", ($resDetalle['json']['data']['nombre'] ?? '') === 'Monitor de Signos Vitales 5 Parámetros');
assertTest("data.sku coincide", ($resDetalle['json']['data']['sku'] ?? '') === 'BIO-MON-001');

// 4. CASOS DE ERROR: INEXISTENTE E ID INVÁLIDO
echo "\n--- 4. Casos de Error (404 Not Found y 400 Bad Request) ---\n";
$res404 = callGetProduct('/api/v1/productos/999999', $sessionId);
assertTest("Producto inexistente retorna success: false", ($res404['json']['success'] ?? true) === false);
assertTest("Informa 'Producto no encontrado' (404)", str_contains($res404['json']['error'] ?? '', 'Producto no encontrado'));

$res400 = callGetProduct('/api/v1/productos/no-valido', $sessionId);
assertTest("ID no numérico retorna error 'ID inválido' (400)", str_contains($res400['json']['error'] ?? '', 'ID inválido'));

// 5. PROTECCIÓN DE SESIÓN (401 Unauthorized sin autenticación)
echo "\n--- 5. Verificación de Protección de Sesión (401 Unauthorized) ---\n";
$resSinSesionList = callGetProduct('/api/v1/productos');
assertTest("Listado sin sesión retorna error 401", ($resSinSesionList['json']['error'] ?? '') === 'No autenticado');

$resSinSesionDetalle = callGetProduct('/api/v1/productos/1');
assertTest("Detalle sin sesión retorna error 401", ($resSinSesionDetalle['json']['error'] ?? '') === 'No autenticado');

@unlink($runnerScript);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
