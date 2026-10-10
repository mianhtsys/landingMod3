<?php
declare(strict_types=1);

// Test automatizado para CP-BACK-08: DELETE lógico productos (estado='inactivo', nunca DELETE FROM)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-BACK-08: DELETE LÓGICO PRODUCTOS (S3IH)\n";
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
$runnerScript = __DIR__ . '/runner_delete_producto.php';

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
    VALUES (1, 'Supervisor Farmacia H1', :email, :hash, 'activo')
");
$stmtUser->execute(['email' => $emailDemo, 'hash' => $hashDemo]);

// Crear categoría activa
$pdo->exec("
    INSERT INTO cat_categoria (id, nombre, categoria_padre_id, estado)
    VALUES (10, 'Equipamiento Biomédico', NULL, 'activo')
");

// Insertar 2 productos activos para pruebas
$pdo->exec("
    INSERT INTO cat_producto (id, sku, nombre, categoria_id, unidad, estado)
    VALUES 
    (1, 'BIO-MON-001', 'Monitor Multiparámetro UCI', 10, 'EQUIPO', 'activo'),
    (2, 'BIO-OX-002', 'Oxímetro de Pulso Hospitalario', 10, 'UNIDAD', 'activo')
");

// Helper para obtener sessionId activo
function getAuthSessionId(): string
{
    global $phpBinary, $emailDemo, $passDemo;
    $loginRunner = __DIR__ . '/runner_temp_login_del.php';
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
    file_put_contents(__DIR__ . '/active_sess_del.txt', session_id());
});
$router->dispatch(new Request());
PHP
    , $emailDemo, $passDemo));

    shell_exec("\"{$phpBinary}\" \"{$loginRunner}\" 2>&1");
    @unlink($loginRunner);
    $sessId = trim((string)file_get_contents(__DIR__ . '/active_sess_del.txt'));
    @unlink(__DIR__ . '/active_sess_del.txt');
    return $sessId;
}

$sessionId = getAuthSessionId();

function callApiProduct(string $method, string $uri, ?string $sess = null): array
{
    global $phpBinary, $runnerScript;
    $code = sprintf(<<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\Request;
use App\Core\Router;

$_SERVER['REQUEST_METHOD'] = '%s';
$_SERVER['REQUEST_URI'] = '%s';
$_SERVER['CONTENT_TYPE'] = 'application/json';

$sess = '%s';
if ($sess !== '') {
    $_COOKIE['s3ih_session'] = $sess;
}

Request::$testInput = null;

$router = new Router();
foreach (require dirname(__DIR__) . '/routes/api.php' as [$m, $pattern, $handler]) {
    $router->add($m, $pattern, $handler);
}
$router->dispatch(new Request());
PHP
    , $method, $uri, $sess ?? '');

    file_put_contents($runnerScript, $code);
    $output = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
    $json = json_decode((string)$output, true);
    return ['output' => (string)$output, 'json' => $json];
}

// 1. CONTROL DE ACCESO (HTTP 401 Unauthorized sin sesión)
echo "--- 1. Control de Acceso (HTTP 401 Unauthorized) ---\n";
$resSinSesion = callApiProduct('DELETE', '/api/v1/productos/1', null);
assertTest("DELETE sin sesión retorna error 401", ($resSinSesion['json']['error'] ?? '') === 'No autenticado');
assertTest("success es false sin sesión", ($resSinSesion['json']['success'] ?? true) === false);

// 2. INACTIVACIÓN LÓGICA EXITOSA (HTTP 200 OK con sesión)
echo "\n--- 2. Inactivación Lógica Exitosa (HTTP 200 OK) ---\n";
$resOk = callApiProduct('DELETE', '/api/v1/productos/1', $sessionId);

assertTest("DELETE responde JSON válido", is_array($resOk['json']));
assertTest("success es true en inactivación lógica", ($resOk['json']['success'] ?? false) === true);
assertTest("data.id corresponde al producto inactivado (1)", ($resOk['json']['data']['id'] ?? 0) === 1);
assertTest("data.estado cambió a 'inactivo'", ($resOk['json']['data']['estado'] ?? '') === 'inactivo');
assertTest("data.sku se conserva íntegro ('BIO-MON-001')", ($resOk['json']['data']['sku'] ?? '') === 'BIO-MON-001');
assertTest("data.nombre se conserva ('Monitor Multiparámetro UCI')", ($resOk['json']['data']['nombre'] ?? '') === 'Monitor Multiparámetro UCI');

// Verificación directa en base de datos: Cero eliminación física (RN-012)
$stmtDb = $pdo->prepare("SELECT COUNT(*) as total, estado FROM cat_producto WHERE id = 1 GROUP BY estado");
$stmtDb->execute();
$rowDb = $stmtDb->fetch();
assertTest("El registro sigue existiendo en BD (COUNT = 1, sin DELETE FROM físico)", ($rowDb['total'] ?? 0) == 1);
assertTest("El campo estado en BD es exactamente 'inactivo'", ($rowDb['estado'] ?? '') === 'inactivo');

// Verificar que el producto 2 no fue afectado
$stmtDb2 = $pdo->prepare("SELECT estado FROM cat_producto WHERE id = 2");
$stmtDb2->execute();
$rowDb2 = $stmtDb2->fetch();
assertTest("El producto 2 no fue afectado y permanece 'activo'", ($rowDb2['estado'] ?? '') === 'activo');

// 3. IDEMPOTENCIA (Doble DELETE → 200 OK)
echo "\n--- 3. Prueba de Idempotencia (Doble DELETE → 200 OK) ---\n";
$resDoble = callApiProduct('DELETE', '/api/v1/productos/1', $sessionId);
assertTest("Segundo DELETE consecutivo retorna success: true", ($resDoble['json']['success'] ?? false) === true);
assertTest("data.estado sigue siendo 'inactivo' tras segundo DELETE", ($resDoble['json']['data']['estado'] ?? '') === 'inactivo');

// 4. CASOS DE ERROR (404 Not Found & 400 Bad Request)
echo "\n--- 4. Casos de Error (404 Not Found & 400 Bad Request) ---\n";
$resInexistente = callApiProduct('DELETE', '/api/v1/productos/999999', $sessionId);
assertTest("Producto inexistente retorna success: false", ($resInexistente['json']['success'] ?? true) === false);
assertTest("Informa error 'Producto no encontrado'", str_contains($resInexistente['json']['error'] ?? '', 'Producto no encontrado'));

$resIdInvalido = callApiProduct('DELETE', '/api/v1/productos/abc-xyz', $sessionId);
assertTest("ID alfanumérico retorna error 400", str_contains($resIdInvalido['json']['error'] ?? '', 'ID inválido'));

$resIdCero = callApiProduct('DELETE', '/api/v1/productos/0', $sessionId);
assertTest("ID cero retorna error 400", str_contains($resIdCero['json']['error'] ?? '', 'ID inválido'));

// 5. VERIFICACIÓN DE REFLEJO EN LISTADO (GET /api/v1/productos)
echo "\n--- 5. Verificación en Listado (GET /api/v1/productos) ---\n";
$resList = callApiProduct('GET', '/api/v1/productos', $sessionId);
assertTest("GET /productos responde success: true", ($resList['json']['success'] ?? false) === true);
$items = $resList['json']['data'] ?? [];
$prod1 = null;
$prod2 = null;
foreach ($items as $item) {
    if ($item['id'] === 1) $prod1 = $item;
    if ($item['id'] === 2) $prod2 = $item;
}
assertTest("Producto 1 aparece en listado con estado 'inactivo'", ($prod1['estado'] ?? '') === 'inactivo');
assertTest("Producto 2 aparece en listado con estado 'activo'", ($prod2['estado'] ?? '') === 'activo');

// 6. AUDITORÍA ESTÁTICA DE CÓDIGO (RN-012 Cero Sentencias Destructivas)
echo "\n--- 6. Verificación de Inmutabilidad Hospitalaria (RN-012) ---\n";
$repoCode = file_get_contents(dirname(__DIR__) . '/app/Repositories/ProductoRepository.php');
$codeWithoutComments = preg_replace('#//.*$|/\*.*?\*/#m', '', (string)$repoCode);
assertTest("El código ejecutable de ProductoRepository NO contiene sentencias 'DELETE FROM'", !stripos((string)$codeWithoutComments, 'DELETE FROM'));
assertTest("ProductoRepository usa exclusivamente UPDATE SET estado = 'inactivo'", str_contains((string)$repoCode, "UPDATE") && str_contains((string)$repoCode, "estado = 'inactivo'"));

@unlink($runnerScript);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
