<?php
declare(strict_types=1);

// Test automatizado para CP-FRONT-06: Listado de productos (S3IH)
// Valida control de acceso, renderizado HTML accesible, ausencia de SQL en capas web e integración con módulos JS.

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-FRONT-06: LISTADO DE PRODUCTOS (S3IH)\n";
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
$runnerScript = __DIR__ . '/runner_front_productos.php';

// Limpieza y preparación de usuario para simular sesión activa
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE auth_usuario");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

$emailDemo = envRequired('DEMO_USER_EMAIL');
$passDemo = envRequired('DEMO_USER_PASSWORD');
$hashDemo = password_hash($passDemo, PASSWORD_ARGON2ID);
$stmtUser = $pdo->prepare("
    INSERT INTO auth_usuario (id, nombre, email, hash_password, estado)
    VALUES (1, 'Ing. Carlos Mendoza - Biomédica Central', :email, :hash, 'activo')
");
$stmtUser->execute(['email' => $emailDemo, 'hash' => $hashDemo]);

// Helper para iniciar sesión PHP y obtener cookie id
function getActiveSessionId(): string
{
    global $phpBinary, $emailDemo, $passDemo;
    $sessLoginRunner = __DIR__ . '/runner_temp_sess_front6.php';
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
    file_put_contents(__DIR__ . '/active_sess_front6.txt', session_id());
});
$router->dispatch(new Request());
PHP
    , $emailDemo, $passDemo));

    shell_exec("\"{$phpBinary}\" \"{$sessLoginRunner}\" 2>&1");
    @unlink($sessLoginRunner);
    $sid = trim((string)file_get_contents(__DIR__ . '/active_sess_front6.txt'));
    @unlink(__DIR__ . '/active_sess_front6.txt');
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
// 1. CONTROL DE ACCESO
// ============================================================
echo "--- 1. Control de Acceso (/productos) ---\n";
$resAnon = callWebRoute('/productos', null);
assertTest("Acceso sin sesión redirige (no renderiza contenido de productos)", !str_contains($resAnon['output'], 'prod-tbody'));

$resAuth = callWebRoute('/productos', $sessionId);
$html = $resAuth['output'];
assertTest("Acceso con sesión activa renderiza exitosamente", str_contains($html, 'prod-tbody'));

// ============================================================
// 2. ESTRUCTURA SEMÁNTICA Y NAVEGACIÓN ACTIVA
// ============================================================
echo "\n--- 2. Estructura Semántica y Navegación ---\n";
assertTest("Estructura base HTML5 (<!DOCTYPE html>)", str_contains($html, '<!DOCTYPE html>'));
assertTest("Título institucional en <title>", str_contains($html, 'Catálogo de Productos') && str_contains($html, 'S3IH'));
assertTest("Skip-link accesible presente", str_contains($html, '<a class="skip-link" href="#contenido">'));
assertTest("Enlace /productos con aria-current=\"page\" en sidebar", str_contains($html, '<a href="/productos" aria-current="page">Productos</a>'));
assertTest("Encabezado <h1> con título de catálogo de productos", str_contains($html, '<h1>Catálogo de Productos y Equipos</h1>'));
assertTest("Nombre del usuario renderizado en el layout", str_contains($html, 'Ing. Carlos Mendoza - Biomédica Central'));

// ============================================================
// 3. TABLA Y COMPONENTES REACTIVOS DE PRESENTACIÓN
// ============================================================
echo "\n--- 3. Elementos de Presentación y Tabla Accesible ---\n";
assertTest("Contenedor de error accesible con role=\"alert\" (#prod-error)", str_contains($html, 'id="prod-error"') && str_contains($html, 'role="alert"') && str_contains($html, 'hidden'));
assertTest("Indicador de estado de carga (#prod-loading)", str_contains($html, 'id="prod-loading"'));
assertTest("Indicador de catálogo vacío (#prod-empty)", str_contains($html, 'id="prod-empty"') && str_contains($html, 'hidden'));
assertTest("Envoltorio de tabla accesible (<div class=\"table-wrap\">)", str_contains($html, '<div class="table-wrap">'));
assertTest("Tabla semántica (<table id=\"prod-table\")", str_contains($html, '<table id="prod-table"'));
assertTest("Encabezados accesibles (<th scope=\"col\">)", 
    str_contains($html, '<th scope="col">ID</th>') && 
    str_contains($html, '<th scope="col">SKU</th>') && 
    str_contains($html, '<th scope="col">Nombre</th>') && 
    str_contains($html, '<th scope="col">Categoría</th>') && 
    str_contains($html, '<th scope="col">Unidad</th>') && 
    str_contains($html, '<th scope="col">Estado</th>') && 
    str_contains($html, '<th scope="col">Acciones</th>'));
assertTest("Cuerpo de tabla con id 'prod-tbody'", str_contains($html, '<tbody id="prod-tbody"></tbody>'));
assertTest("Inclusión de módulo JS (/assets/js/modules/productos.js)", str_contains($html, '<script type="module" src="/assets/js/modules/productos.js"></script>'));

// ============================================================
// 4. AUDITORÍA DE ARQUITECTURA: CERO SQL EN CAPA DE PRESENTACIÓN
// ============================================================
echo "\n--- 4. Auditoría de Arquitectura: Cero SQL en Presentación ---\n";
$viewCode = (string)file_get_contents(dirname(__DIR__) . '/app/Views/productos/index.php');
$controllerCode = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/Web/ProductoController.php');

$cleanView = strip_tags(preg_replace('#//.*$|/\*.*?\*/#m', '', $viewCode));
$cleanController = preg_replace('#//.*$|/\*.*?\*/#m', '', $controllerCode);

assertTest("Vista productos/index.php NO contiene sentencias SQL (SELECT ..., INSERT INTO, etc.)", 
    !preg_match('/\b(SELECT\s+[\w\*,\s]+FROM|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $cleanView));
assertTest("Vista productos/index.php NO invoca PDO ni conexiones a base de datos", 
    !str_contains($viewCode, 'PDO') && !str_contains($viewCode, 'Database::'));
assertTest("ProductoController NO contiene sentencias SQL ni llamadas a base de datos", 
    !preg_match('/\b(SELECT\s+[\w\*,\s]+FROM|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $cleanController) && !str_contains($cleanController, 'Database::'));

// ============================================================
// 5. INTEGRIDAD DE MÓDULOS JAVASCRIPT (ES6+)
// ============================================================
echo "\n--- 5. Integridad de Módulos JavaScript ---\n";
$publicDir = dirname(__DIR__) . '/public';
$prodJs = (string)file_get_contents($publicDir . '/assets/js/modules/productos.js');
$apiJs = (string)file_get_contents($publicDir . '/assets/js/api/productos-api.js');

assertTest("productos.js importa listProductos desde API", str_contains($prodJs, 'listProductos'));
assertTest("productos.js incluye función de escape contra XSS (esc)", str_contains($prodJs, 'function esc('));
assertTest("productos.js aplica clases badge-ok y badge-off según estado", str_contains($prodJs, 'badge badge-'));
assertTest("productos.js interactúa con prod-tbody, prod-loading y prod-table", 
    str_contains($prodJs, 'prod-tbody') && str_contains($prodJs, 'prod-loading') && str_contains($prodJs, 'prod-table'));
assertTest("productos-api.js consume /api/v1/productos con same-origin", 
    str_contains($apiJs, "'/api/v1/productos'") && str_contains($apiJs, "credentials: 'same-origin'"));

// ============================================================
// 6. INTEGRACIÓN FUNCIONAL CON BACKEND REST
// ============================================================
echo "\n--- 6. Integración Funcional con Backend REST ---\n";
$runnerApi = __DIR__ . '/runner_tmp_front6_api.php';
file_put_contents($runnerApi, sprintf(<<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\Request;
use App\Core\Router;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/v1/productos';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_COOKIE['s3ih_session'] = '%s';
Request::$testInput = null;
$router = new Router();
foreach (require dirname(__DIR__) . '/routes/api.php' as [$m, $pattern, $handler]) {
    $router->add($m, $pattern, $handler);
}
$router->dispatch(new Request());
PHP
, $sessionId));

$outApi = shell_exec("\"{$phpBinary}\" \"{$runnerApi}\" 2>&1");
@unlink($runnerApi);
$jsonApi = json_decode((string)$outApi, true) ?? [];

assertTest("GET /api/v1/productos responde success: true para el listado", ($jsonApi['success'] ?? false) === true);
assertTest("GET /api/v1/productos data es un array", is_array($jsonApi['data'] ?? null));

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
