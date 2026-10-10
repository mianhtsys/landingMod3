<?php
declare(strict_types=1);

// Test automatizado para CP-BACK-06: POST productos + fixtures (válidos, inválidos, 404, 409 y 401)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-BACK-06: POST PRODUCTOS + FIXTURES (S3IH)\n";
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
$runnerScript = __DIR__ . '/runner_post_producto.php';

// Limpieza controlada y preparación de entorno
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

// Crear categoría activa para asignación de productos
$pdo->exec("
    INSERT INTO cat_categoria (id, nombre, categoria_padre_id, estado)
    VALUES (100, 'Equipamiento Biomédico y Terapia Intensiva', NULL, 'activo')
");

// Helper para obtener sessionId activo
function getAuthSessionId(): string
{
    global $phpBinary, $emailDemo, $passDemo;
    $loginRunner = __DIR__ . '/runner_temp_login_post.php';
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
    file_put_contents(__DIR__ . '/active_sess_post.txt', session_id());
});
$router->dispatch(new Request());
PHP
    , $emailDemo, $passDemo));

    shell_exec("\"{$phpBinary}\" \"{$loginRunner}\" 2>&1");
    @unlink($loginRunner);
    $sessId = trim((string)file_get_contents(__DIR__ . '/active_sess_post.txt'));
    @unlink(__DIR__ . '/active_sess_post.txt');
    return $sessId;
}

$sessionId = getAuthSessionId();

function callPostProduct(string $payloadJson, ?string $sess = null, string $contentType = 'application/json'): array
{
    global $phpBinary, $runnerScript;
    $payloadFile = __DIR__ . '/tmp_payload_product.json';
    file_put_contents($payloadFile, $payloadJson);

    $code = sprintf(<<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\Request;
use App\Core\Router;

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/v1/productos';
$_SERVER['CONTENT_TYPE'] = '%s';

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
    , $contentType, $sess ?? '', addcslashes($payloadFile, "'\\"));

    file_put_contents($runnerScript, $code);
    $output = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
    @unlink($payloadFile);
    $json = json_decode((string)$output, true);
    return ['output' => (string)$output, 'json' => $json];
}

// 1. FIXTURES VÁLIDOS (HTTP 201 Created)
echo "--- 1. Creación Exitosa de Producto Hospitalario (HTTP 201 Created) ---\n";
$payloadValido1 = json_encode([
    'sku' => 'BIO-BOM-001',
    'nombre' => 'Bomba de Infusión Volumétrica Peristáltica',
    'categoria_id' => 100,
    'unidad' => 'UNIDAD'
]);
$resOk = callPostProduct($payloadValido1, $sessionId);

assertTest("Producto creado retorna JSON válido", is_array($resOk['json']));
assertTest("success es true en 201 Created", ($resOk['json']['success'] ?? false) === true);
$idCreado = $resOk['json']['data']['id'] ?? 0;
assertTest("ID autonumérico generado", is_int($idCreado) && $idCreado > 0);
assertTest("SKU persistido correctamente", ($resOk['json']['data']['sku'] ?? '') === 'BIO-BOM-001');
assertTest("Nombre persistido correctamente", ($resOk['json']['data']['nombre'] ?? '') === 'Bomba de Infusión Volumétrica Peristáltica');
assertTest("categoria_id vinculado a 100", ($resOk['json']['data']['categoria_id'] ?? 0) === 100);
assertTest("Unidad guardada en UNIDAD", ($resOk['json']['data']['unidad'] ?? '') === 'UNIDAD');
assertTest("Estado inicial es 'activo'", ($resOk['json']['data']['estado'] ?? '') === 'activo');

// 2. FIXTURES DE VALIDACIÓN (HTTP 400 Bad Request)
echo "\n--- 2. Fixtures de Validación de Campos (HTTP 400 Bad Request) ---\n";

// Caso 2.1: SKU ausente
$resSinSku = callPostProduct(json_encode([
    'nombre' => 'Desfibrilador Bifásico',
    'categoria_id' => 100,
    'unidad' => 'UNIDAD'
]), $sessionId);
assertTest("SKU ausente retorna error 400", str_contains($resSinSku['json']['error'] ?? '', 'sku: obligatorio'));

// Caso 2.2: Nombre vacío
$resNombreVacio = callPostProduct(json_encode([
    'sku' => 'BIO-DES-002',
    'nombre' => '   ',
    'categoria_id' => 100,
    'unidad' => 'UNIDAD'
]), $sessionId);
assertTest("Nombre vacío retorna error 400", str_contains($resNombreVacio['json']['error'] ?? '', 'nombre: obligatorio'));

// Caso 2.3: categoria_id inválido (negativo o string)
$resCatInvalida = callPostProduct(json_encode([
    'sku' => 'BIO-DES-003',
    'nombre' => 'Monitor Fetal Doppler',
    'categoria_id' => -5,
    'unidad' => 'UNIDAD'
]), $sessionId);
assertTest("categoria_id negativo retorna error 400", str_contains($resCatInvalida['json']['error'] ?? '', 'categoria_id: entero mayor 0'));

// Caso 2.4: Unidad ausente
$resSinUnidad = callPostProduct(json_encode([
    'sku' => 'BIO-DES-004',
    'nombre' => 'Electrocardiógrafo 12 Canales',
    'categoria_id' => 100
]), $sessionId);
assertTest("Unidad ausente retorna error 400", str_contains($resSinUnidad['json']['error'] ?? '', 'unidad: obligatoria'));

// 3. FIXTURE DE CATEGORÍA INEXISTENTE (HTTP 404 Not Found)
echo "\n--- 3. Fixture de Clave Foránea Inexistente (HTTP 404 Not Found) ---\n";
$resCatInexistente = callPostProduct(json_encode([
    'sku' => 'BIO-OXI-005',
    'nombre' => 'Concentrador de Oxígeno 10L',
    'categoria_id' => 999999,
    'unidad' => 'UNIDAD'
]), $sessionId);
assertTest("categoria_id inexistente retorna 404", str_contains($resCatInexistente['json']['error'] ?? '', 'categoria_id no existe'));

// 4. FIXTURE DE SKU DUPLICADO (HTTP 409 Conflict)
echo "\n--- 4. Fixture de Conflicto de Unicidad SKU (HTTP 409 Conflict) ---\n";
$resSkuDuplicado = callPostProduct($payloadValido1, $sessionId);
assertTest("SKU duplicado retorna success: false", ($resSkuDuplicado['json']['success'] ?? true) === false);
assertTest("SKU duplicado informa error 409 'sku ya existe'", str_contains($resSkuDuplicado['json']['error'] ?? '', 'sku ya existe'));

// 5. PROTECCIÓN DE SESIÓN (HTTP 401 Unauthorized)
echo "\n--- 5. Verificación de Protección de Sesión (HTTP 401 Unauthorized) ---\n";
$resSinSesion = callPostProduct($payloadValido1, null);
assertTest("Petición sin sesión retorna 401 Unauthorized", ($resSinSesion['json']['error'] ?? '') === 'No autenticado');

@unlink($runnerScript);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
