<?php
declare(strict_types=1);

// Test automatizado para CP-FRONT-02: Layout autenticado + Dashboard (S3IH)
// Valida redirecciones de sesión, skip-link, sidebar institucional, métricas operativas y módulos JS.

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-FRONT-02: LAYOUT + DASHBOARD (S3IH)\n";
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
$runnerScript = __DIR__ . '/runner_front_dashboard.php';

// Limpieza y creación de usuario en base de datos para simular sesión activa
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE auth_usuario");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

$emailDemo = envRequired('DEMO_USER_EMAIL');
$passDemo = envRequired('DEMO_USER_PASSWORD');
$hashDemo = password_hash($passDemo, PASSWORD_ARGON2ID);
$stmtUser = $pdo->prepare("
    INSERT INTO auth_usuario (id, nombre, email, hash_password, estado)
    VALUES (1, 'Dr. Arnaldo Silva - Jefe Guardia H1', :email, :hash, 'activo')
");
$stmtUser->execute(['email' => $emailDemo, 'hash' => $hashDemo]);

// Helper para iniciar sesión PHP y obtener cookie id
function getActiveSessionId(): string
{
    global $phpBinary, $emailDemo, $passDemo;
    $sessLoginRunner = __DIR__ . '/runner_temp_sess_front.php';
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
    file_put_contents(__DIR__ . '/active_sess_front2.txt', session_id());
});
$router->dispatch(new Request());
PHP
    , $emailDemo, $passDemo));

    shell_exec("\"{$phpBinary}\" \"{$sessLoginRunner}\" 2>&1");
    @unlink($sessLoginRunner);
    $sid = trim((string)file_get_contents(__DIR__ . '/active_sess_front2.txt'));
    @unlink(__DIR__ . '/active_sess_front2.txt');
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
// 1. CONTROL DE ACCESO Y REDIRECCIONES DE RUTA
// ============================================================
echo "--- 1. Control de Acceso y Redirecciones de Ruta ---\n";

// Caso 1.1: GET / sin sesión -> Redirección a /login (no emite HTML del dashboard)
$resRootAnon = callWebRoute('/', null);
assertTest("GET / sin sesión redirige (no contiene contenido del dashboard)", !str_contains($resRootAnon['output'], 'main-content'));

// Caso 1.2: GET /dashboard sin sesión -> Redirección a /login
$resDashAnon = callWebRoute('/dashboard', null);
assertTest("GET /dashboard sin sesión redirige (no contiene contenido del dashboard)", !str_contains($resDashAnon['output'], 'main-content'));

// Caso 1.3: GET / con sesión -> Redirección a /dashboard
$resRootAuth = callWebRoute('/', $sessionId);
assertTest("GET / con sesión redirige a dashboard (no emite vista de login)", !str_contains($resRootAuth['output'], '<main class="auth-card">'));

// ============================================================
// 2. RENDERIZADO DEL LAYOUT AUTENTICADO
// ============================================================
echo "\n--- 2. Estructura Semántica del Layout Autenticado ---\n";
$resDash = callWebRoute('/dashboard', $sessionId);
$html = $resDash['output'];

assertTest("Estructura base HTML5 (<!DOCTYPE html>)", str_contains($html, '<!DOCTYPE html>'));
assertTest("Página con clase autenticada (<body class=\"page-main\">)", str_contains($html, '<body class="page-main">'));
assertTest("Skip-link accesible presente (#contenido)", str_contains($html, '<a class="skip-link" href="#contenido">Saltar al contenido</a>'));
assertTest("Contenedor principal con foco accesible (<main id=\"contenido\" class=\"main-content\" tabindex=\"-1\">)", str_contains($html, '<main id="contenido" class="main-content" tabindex="-1">'));

// ============================================================
// 3. BARRA LATERAL (SIDEBAR) INSTITUCIONAL
// ============================================================
echo "\n--- 3. Navegación y Sidebar Institucional S3IH ---\n";
assertTest("Sidebar semántico (<aside class=\"sidebar\">)", str_contains($html, '<aside class="sidebar">'));
assertTest("Branding institucional 'S3IH Hospitalario'", str_contains($html, 'S3IH Hospitalario'));
assertTest("Nav accesible con etiqueta (<nav aria-label=\"Navegación principal\">)", str_contains($html, '<nav aria-label="Navegación principal">'));
assertTest("Enlace /dashboard activo con aria-current=\"page\"", str_contains($html, '<a href="/dashboard" aria-current="page">Dashboard</a>'));
assertTest("Enlace accesible a /categorias", str_contains($html, '<a href="/categorias">Categorías</a>'));
assertTest("Enlace accesible a /productos", str_contains($html, '<a href="/productos">Productos</a>'));
assertTest("Nombre del usuario renderizado en el sidebar", str_contains($html, 'Dr. Arnaldo Silva - Jefe Guardia H1'));
assertTest("Botón de cierre de sesión accesible (#logout-btn)", str_contains($html, 'id="logout-btn"') && str_contains($html, 'Salir'));

// ============================================================
// 4. VISTA Y TARJETAS OPERATIVAS DEL DASHBOARD
// ============================================================
echo "\n--- 4. Métricas y Tarjetas del Dashboard ---\n";
assertTest("Título principal presente (Centro de Control o Dashboard)", str_contains($html, 'Centro de Control') || str_contains($html, 'Dashboard'));
assertTest("Saludo con usuario de sesión verificado", str_contains($html, 'Dr. Arnaldo Silva - Jefe Guardia H1'));
assertTest("Contenedor de error accesible con role=\"alert\" (#dash-error)", str_contains($html, 'id="dash-error"') && str_contains($html, 'role="alert"') && str_contains($html, 'hidden'));
assertTest("Sección semántica de tarjetas operativas (<section class=\"cards\"", str_contains($html, '<section class="cards"'));
assertTest("Métrica de categorías con contenedor reactivo (#count-categorias, aria-live=\"polite\")", str_contains($html, 'id="count-categorias"') && str_contains($html, 'aria-live="polite"'));
assertTest("Métrica de productos con contenedor reactivo (#count-productos, aria-live=\"polite\")", str_contains($html, 'id="count-productos"') && str_contains($html, 'aria-live="polite"'));
assertTest("Enlace directo a listado de categorías desde tarjeta", str_contains($html, 'href="/categorias"'));
assertTest("Enlace directo a catálogo de productos desde tarjeta", str_contains($html, 'href="/productos"'));

// ============================================================
// 5. SCRIPTS Y MÓDULOS JAVASCRIPT ASOCIADOS
// ============================================================
echo "\n--- 5. Módulos y Dependencias JavaScript ---\n";
assertTest("Inclusión de script principal del shell (/assets/js/app.js)", str_contains($html, '<script type="module" src="/assets/js/app.js"></script>'));
assertTest("Inclusión de módulo específico (/assets/js/modules/dashboard.js)", str_contains($html, '<script type="module" src="/assets/js/modules/dashboard.js"></script>'));

$publicDir = dirname(__DIR__) . '/public';
$appJs = (string)file_get_contents($publicDir . '/assets/js/app.js');
$dashJs = (string)file_get_contents($publicDir . '/assets/js/modules/dashboard.js');

assertTest("app.js engancha evento click en #logout-btn", str_contains($appJs, "document.getElementById('logout-btn')") && str_contains($appJs, 'addEventListener'));
assertTest("dashboard.js invoca listCategorias para métricas", str_contains($dashJs, 'listCategorias'));
assertTest("dashboard.js invoca listProductos para métricas", str_contains($dashJs, 'listProductos'));

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
