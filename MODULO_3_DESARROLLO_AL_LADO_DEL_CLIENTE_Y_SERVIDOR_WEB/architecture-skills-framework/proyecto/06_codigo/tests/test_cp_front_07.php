<?php
declare(strict_types=1);

// Test automatizado para CP-FRONT-07: Alta de productos (S3IH)
// Valida formulario accesible, elementos ARIA, validaciones cliente, auditoría cero SQL e integración e2e con backend.

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-FRONT-07: ALTA DE PRODUCTOS (S3IH)\n";
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
$runnerScript = __DIR__ . '/runner_front_alta_prod.php';

// Limpieza y preparación de fixtures
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE auth_usuario");
$pdo->exec("TRUNCATE TABLE cat_producto");
$pdo->exec("TRUNCATE TABLE cat_categoria");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

$emailDemo = envRequired('DEMO_USER_EMAIL');
$passDemo = envRequired('DEMO_USER_PASSWORD');
$hashDemo = password_hash($passDemo, PASSWORD_ARGON2ID);
$stmtUser = $pdo->prepare("
    INSERT INTO auth_usuario (id, nombre, email, hash_password, estado)
    VALUES (1, 'Ing. Carlos Mendoza - Biomédica Central', :email, :hash, 'activo')
");
$stmtUser->execute(['email' => $emailDemo, 'hash' => $hashDemo]);

// Crear categoría para pruebas de FK
$pdo->exec("
    INSERT INTO cat_categoria (id, nombre, categoria_padre_id, estado)
    VALUES (100, 'Equipamiento Electromédico Crítico', NULL, 'activo')
");

// Helper para iniciar sesión PHP y obtener cookie id
function getActiveSessionId(): string
{
    global $phpBinary, $emailDemo, $passDemo;
    $sessLoginRunner = __DIR__ . '/runner_temp_sess_front7.php';
    file_put_contents($sessLoginRunner, sprintf(<<<'PHP'
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
    file_put_contents(__DIR__ . '/active_sess_front7.txt', session_id());
});
$router->dispatch(new Request());
PHP
    , $emailDemo, $passDemo));

    shell_exec("\"{$phpBinary}\" \"{$sessLoginRunner}\" 2>&1");
    @unlink($sessLoginRunner);
    $sid = trim((string)file_get_contents(__DIR__ . '/active_sess_front7.txt'));
    @unlink(__DIR__ . '/active_sess_front7.txt');
    return $sid;
}

$sessionId = getActiveSessionId();

function callWebRoute(string $uri, ?string $sessionCookie = null): array
{
    global $phpBinary, $runnerScript;
    $code = sprintf(<<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\Request;
use App\Core\Router;

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '%s';

$sess = '%s';
if ($sess !== '') {
    $_COOKIE['s3ih_session'] = $sess;
}

$router = new Router();
foreach (require dirname(__DIR__) . '/routes/web.php' as [$m, $pattern, $handler]) {
    $router->add($m, $pattern, $handler);
}

$router->dispatch(new Request());
PHP
    , $uri, $sessionCookie ?? '');

    file_put_contents($runnerScript, $code);
    $output = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
    @unlink($runnerScript);

    return ['output' => (string)$output];
}

// ============================================================
// 1. RENDERIZADO DEL FORMULARIO DE ALTA DE PRODUCTOS
// ============================================================
echo "--- 1. Renderizado Accesible del Formulario de Alta ---\n";
$resView = callWebRoute('/productos', $sessionId);
$html = $resView['output'];

assertTest("Sección de formulario presente (<section class=\"form-section\")", str_contains($html, '<section class="form-section"'));
assertTest("Sección vinculada con aria-labelledby (#prod-new-title)", str_contains($html, 'aria-labelledby="prod-new-title"'));
assertTest("Encabezado h2 'Nuevo producto' (#prod-new-title)", str_contains($html, '<h2 id="prod-new-title">Nuevo producto</h2>'));
assertTest("Formulario con novalidate (#prod-new-form)", str_contains($html, '<form id="prod-new-form" novalidate>'));
assertTest("Campo SKU accesible (#prod-sku, required, maxlength=64)", 
    str_contains($html, 'id="prod-sku"') && str_contains($html, 'name="sku"') && str_contains($html, 'required') && str_contains($html, 'maxlength="64"'));
assertTest("Campo nombre accesible (#prod-nombre, required, maxlength=255)", 
    str_contains($html, 'id="prod-nombre"') && str_contains($html, 'name="nombre"') && str_contains($html, 'required') && str_contains($html, 'maxlength="255"'));
assertTest("Selector de categoría accesible (#prod-cat, name=\"categoria_id\", required)", 
    str_contains($html, '<select id="prod-cat" name="categoria_id" required>'));
assertTest("Campo unidad accesible (#prod-unidad, required, maxlength=32)", 
    str_contains($html, 'id="prod-unidad"') && str_contains($html, 'name="unidad"') && str_contains($html, 'required') && str_contains($html, 'maxlength="32"'));
assertTest("Contenedor de error reactivo (#prod-new-error, role=\"alert\")", 
    str_contains($html, 'id="prod-new-error"') && str_contains($html, 'role="alert"') && str_contains($html, 'hidden'));
assertTest("Contenedor de éxito reactivo (#prod-new-ok, role=\"status\")", 
    str_contains($html, 'id="prod-new-ok"') && str_contains($html, 'role="status"') && str_contains($html, 'hidden'));
assertTest("Botón de envío para creación (#prod-new-submit)", 
    str_contains($html, 'id="prod-new-submit"') && str_contains($html, 'type="submit"') && str_contains($html, 'Crear producto'));
assertTest("Botón de cancelación con estado oculto inicial (#prod-cancel-edit)", 
    str_contains($html, 'id="prod-cancel-edit"') && str_contains($html, 'hidden'));

// ============================================================
// 2. AUDITORÍA DE ARQUITECTURA: CERO SQL EN CAPA DE PRESENTACIÓN
// ============================================================
echo "\n--- 2. Auditoría de Arquitectura: Cero SQL en Presentación ---\n";
$viewCode = (string)file_get_contents(dirname(__DIR__) . '/app/Views/productos/index.php');
$cleanView = strip_tags(preg_replace('#//.*$|/\*.*?\*/#m', '', $viewCode));

assertTest("La vista NO contiene sentencias SQL destructivas ni de inserción directa", 
    !preg_match('/\b(INSERT\s+INTO|SELECT\s+[\w\*,\s]+FROM|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $cleanView));
assertTest("La vista NO contiene referencias a objetos de persistencia PDO ni Database::", 
    !str_contains($viewCode, 'PDO') && !str_contains($viewCode, 'Database::'));

// ============================================================
// 3. INTEGRIDAD DE MÓDULOS JAVASCRIPT (ES6+)
// ============================================================
echo "\n--- 3. Lógica JavaScript para Alta de Productos ---\n";
$publicDir = dirname(__DIR__) . '/public';
$prodJs = (string)file_get_contents($publicDir . '/assets/js/modules/productos.js');
$apiJs = (string)file_get_contents($publicDir . '/assets/js/api/productos-api.js');

assertTest("productos.js importa createProducto y listCategorias", 
    str_contains($prodJs, 'createProducto') && str_contains($prodJs, 'listCategorias'));
assertTest("productos.js intercepta submit del formulario", str_contains($prodJs, "form?.addEventListener('submit'"));
assertTest("productos.js valida obligatoriedad de campos en cliente", 
    str_contains($prodJs, 'SKU, nombre, categoría y unidad son obligatorios.'));
assertTest("productos.js bloquea submitBtn.disabled = true para evitar doble envío", str_contains($prodJs, 'submitBtn.disabled = true'));
assertTest("productos.js emite mensaje 'Producto creado.' en formOk", str_contains($prodJs, "'Producto creado.'"));
assertTest("productos.js despliega errores de validación de API en formErr", str_contains($prodJs, 'formErr.textContent = err.message'));
assertTest("productos-api.js expone función createProducto apuntando a POST /api/v1/productos", 
    str_contains($apiJs, "export const createProducto = (payload) => send('POST', '/api/v1/productos', payload);"));

// ============================================================
// 4. INTEGRACIÓN FUNCIONAL E2E (CREACIÓN, UNICIDAD SKU, FK CATEGORÍA)
// ============================================================
echo "\n--- 4. Integración Funcional con Backend REST ---\n";
function callApiPostProd(string $uri, array $payload, ?string $sess = null): array
{
    global $phpBinary;
    $tmpF = __DIR__ . '/tmp_front7_api.json';
    file_put_contents($tmpF, json_encode($payload));
    $code = sprintf(<<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\Request;
use App\Core\Router;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '%s';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$sess = '%s';
if ($sess !== '') {
    $_COOKIE['s3ih_session'] = $sess;
}
Request::$testInput = file_get_contents('%s');
$router = new Router();
foreach (require dirname(__DIR__) . '/routes/api.php' as [$m, $pattern, $handler]) {
    $router->add($m, $pattern, $handler);
}
$router->dispatch(new Request());
PHP
    , $uri, $sess ?? '', addcslashes($tmpF, "'\\"));
    $runner = __DIR__ . '/runner_tmp_front7_api.php';
    file_put_contents($runner, $code);
    $out = shell_exec("\"{$phpBinary}\" \"{$runner}\" 2>&1");
    @unlink($runner);
    @unlink($tmpF);
    return json_decode((string)$out, true) ?? [];
}

// 4.1 Alta válida
$payloadOk = [
    'sku' => 'BIO-VENT-001',
    'nombre' => 'Ventilador Mecánico de Transporte UCI',
    'categoria_id' => 100,
    'unidad' => 'EQUIPO'
];
$resApiOk = callApiPostProd('/api/v1/productos', $payloadOk, $sessionId);
assertTest("API responde success: true ante alta válida de producto", ($resApiOk['success'] ?? false) === true);
assertTest("API retorna el ID generado del nuevo producto", ($resApiOk['data']['id'] ?? 0) > 0);
assertTest("API retorna estado 'activo' por defecto", ($resApiOk['data']['estado'] ?? '') === 'activo');
assertTest("SKU retornado coincide con el enviado", ($resApiOk['data']['sku'] ?? '') === 'BIO-VENT-001');

// 4.2 Conflicto por SKU repetido (HTTP 409)
$resApiDup = callApiPostProd('/api/v1/productos', $payloadOk, $sessionId);
assertTest("API rechaza SKU repetido retornando success: false", ($resApiDup['success'] ?? true) === false);
assertTest("Informa error 409 'sku ya existe'", str_contains($resApiDup['error'] ?? '', 'sku ya existe'));

// 4.3 Error por categoría inexistente (HTTP 404)
$payloadBadCat = [
    'sku' => 'BIO-VENT-002',
    'nombre' => 'Segundo Ventilador',
    'categoria_id' => 999999,
    'unidad' => 'EQUIPO'
];
$resApiBadCat = callApiPostProd('/api/v1/productos', $payloadBadCat, $sessionId);
assertTest("API rechaza producto con categoría inexistente", ($resApiBadCat['success'] ?? true) === false);
assertTest("Informa error 404 'categoria_id no existe'", str_contains($resApiBadCat['error'] ?? '', 'categoria_id no existe'));

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
