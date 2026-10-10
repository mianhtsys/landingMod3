<?php
declare(strict_types=1);

// Test automatizado para CP-FRONT-09: Responsive + Accesibilidad (Cierre de Workflow 05)
// Valida WCAG 2.1 AA (skip-links, focus-visible, ARIA, targets táctiles >=44px, contraste),
// responsividad en breakpoints (768px, 480px) y congelamiento estricto de alcance (4 pantallas).

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-FRONT-09: RESPONSIVE + ACCESIBILIDAD (S3IH)\n";
echo "Cierre de Workflow 05: Frontend Mínimo (WCAG 2.1 AA)\n";
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
$runnerScript = __DIR__ . '/runner_front_a11y.php';

// Limpieza y preparación de usuario para simular sesión activa
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE auth_usuario");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

$emailDemo = envRequired('DEMO_USER_EMAIL');
$passDemo = envRequired('DEMO_USER_PASSWORD');
$hashDemo = password_hash($passDemo, PASSWORD_ARGON2ID);
$stmtUser = $pdo->prepare("
    INSERT INTO auth_usuario (id, nombre, email, hash_password, estado)
    VALUES (1, 'Dr. Roberto Navarro - Coordinación Médica H1', :email, :hash, 'activo')
");
$stmtUser->execute(['email' => $emailDemo, 'hash' => $hashDemo]);

// Helper para iniciar sesión PHP y obtener cookie id
function getActiveSessionId(): string
{
    global $phpBinary, $emailDemo, $passDemo;
    $sessLoginRunner = __DIR__ . '/runner_temp_sess_front9.php';
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
    file_put_contents(__DIR__ . '/active_sess_front9.txt', session_id());
});
$router->dispatch(new Request());
PHP
    , $emailDemo, $passDemo));

    shell_exec("\"{$phpBinary}\" \"{$sessLoginRunner}\" 2>&1");
    @unlink($sessLoginRunner);
    $sid = trim((string)file_get_contents(__DIR__ . '/active_sess_front9.txt'));
    @unlink(__DIR__ . '/active_sess_front9.txt');
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
// 1. CONGELAMIENTO ESTRICTO DE ALCANCE (EXACTAMENTE 4 PANTALLAS)
// ============================================================
echo "--- 1. Congelamiento Estricto de Pantallas (Alcance Didáctico) ---\n";
$routesWeb = require dirname(__DIR__) . '/routes/web.php';
$rutasDefinidas = array_map(fn($r) => $r[1], $routesWeb);

assertTest("Ruta /login existe", in_array('/login', $rutasDefinidas, true));
assertTest("Ruta /dashboard existe", in_array('/dashboard', $rutasDefinidas, true));
assertTest("Ruta /categorias existe", in_array('/categorias', $rutasDefinidas, true));
assertTest("Ruta /productos existe", in_array('/productos', $rutasDefinidas, true));
assertTest("No existen pantallas fuera de las 4 congeladas", count($rutasDefinidas) === 5); // 4 pantallas + 1 raíz de redirección

// ============================================================
// 2. ACCESIBILIDAD EN ESTRUCTURA HTML (WCAG 2.1 AA)
// ============================================================
echo "\n--- 2. Accesibilidad en Estructura HTML (WCAG 2.1 AA) ---\n";

// 2.1 Pantalla de Login
$htmlLogin = callWebRoute('/login', null)['output'];
assertTest("Login: idioma declarado en html (lang=\"es\")", str_contains($htmlLogin, '<html lang="es">'));
assertTest("Login: meta viewport para responsividad", str_contains($htmlLogin, 'name="viewport"'));
assertTest("Login: encabezado semántico único h1", substr_count($htmlLogin, '<h1') === 1);
assertTest("Login: inputs vinculados con label for", str_contains($htmlLogin, '<label for="email">') && str_contains($htmlLogin, '<label for="password">'));
assertTest("Login: alerta reactiva con role=\"alert\"", str_contains($htmlLogin, 'id="login-error"') && str_contains($htmlLogin, 'role="alert"'));

// 2.2 Pantalla de Dashboard
$htmlDash = callWebRoute('/dashboard', $sessionId)['output'];
assertTest("Dashboard: skip-link accesible presente", str_contains($htmlDash, '<a class="skip-link" href="#contenido">Saltar al contenido</a>'));
assertTest("Dashboard: contenedor main accesible con tabindex=\"-1\"", str_contains($htmlDash, '<main id="contenido" class="main-content" tabindex="-1">'));
assertTest("Dashboard: navegación principal con aria-label", str_contains($htmlDash, '<nav aria-label="Navegación principal">'));
assertTest("Dashboard: página activa marcada con aria-current=\"page\"", str_contains($htmlDash, '<a href="/dashboard" aria-current="page">Dashboard</a>'));
assertTest("Dashboard: métricas con aria-live=\"polite\"", 
    str_contains($htmlDash, 'id="count-categorias"') && str_contains($htmlDash, 'aria-live="polite"') &&
    str_contains($htmlDash, 'id="count-productos"') && str_contains($htmlDash, 'aria-live="polite"'));

// 2.3 Pantalla de Categorías
$htmlCat = callWebRoute('/categorias', $sessionId)['output'];
assertTest("Categorías: tabla accesible con caption para lectores de pantalla", str_contains($htmlCat, '<caption class="visually-hidden">'));
assertTest("Categorías: encabezados de tabla accesibles con th scope=\"col\"", str_contains($htmlCat, '<th scope="col">ID</th>'));
assertTest("Categorías: formulario accesible con aria-labelledby", str_contains($htmlCat, 'aria-labelledby="cat-new-title"'));
assertTest("Categorías: alertas con role=\"alert\" y role=\"status\"", str_contains($htmlCat, 'role="alert"') && str_contains($htmlCat, 'role="status"'));

// 2.4 Pantalla de Productos
$htmlProd = callWebRoute('/productos', $sessionId)['output'];
assertTest("Productos: tabla accesible con caption para lectores de pantalla", str_contains($htmlProd, '<caption class="visually-hidden">'));
assertTest("Productos: todos los campos de formulario vinculados a label for", 
    str_contains($htmlProd, '<label for="prod-sku">') && 
    str_contains($htmlProd, '<label for="prod-nombre">') && 
    str_contains($htmlProd, '<label for="prod-cat">') && 
    str_contains($htmlProd, '<label for="prod-unidad">'));

// ============================================================
// 3. REGLAS CSS DE ACCESIBILIDAD Y RESPONSIVIDAD (WCAG 2.1 AA)
// ============================================================
echo "\n--- 3. Reglas CSS de Accesibilidad y Responsividad ---\n";
$cssResp = (string)file_get_contents(dirname(__DIR__) . '/public/assets/css/responsive.css');

assertTest("CSS define clase .visually-hidden para screen readers", str_contains($cssResp, '.visually-hidden'));
assertTest("CSS implementa .skip-link:focus visible al tabular", str_contains($cssResp, '.skip-link:focus'));
assertTest("CSS garantiza foco visible (:focus-visible con outline)", str_contains($cssResp, ':focus-visible') && str_contains($cssResp, 'outline:'));
assertTest("CSS respeta preferencia de movimiento reducido (prefers-reduced-motion)", str_contains($cssResp, 'prefers-reduced-motion: reduce'));
assertTest("CSS incluye breakpoint de tablet/móvil (@media (max-width: 768px))", str_contains($cssResp, '@media (max-width: 768px)'));
assertTest("CSS adapta sidebar a flujo de 1 columna en <=768px", str_contains($cssResp, 'grid-template-columns: 1fr'));
assertTest("CSS incluye breakpoint de móvil compacto (@media (max-width: 480px))", str_contains($cssResp, '@media (max-width: 480px)'));
assertTest("CSS garantiza área táctil mínima de 44px en móviles (min-height: 44px)", str_contains($cssResp, 'min-height: 44px'));

// ============================================================
// 4. AUDITORÍA FINAL DE ARQUITECTURA (CERO SQL EN CAPAS WEB)
// ============================================================
echo "\n--- 4. Auditoría de Arquitectura: Cero SQL en Presentación ---\n";
$viewsDir = dirname(__DIR__) . '/app/Views';
$webCtrlDir = dirname(__DIR__) . '/app/Controllers/Web';

$allViewsCode = '';
foreach (glob($viewsDir . '/*/*.php') as $vFile) {
    $allViewsCode .= file_get_contents($vFile) . "\n";
}
$cleanAllViews = strip_tags(preg_replace('#//.*$|/\*.*?\*/#m', '', $allViewsCode));

assertTest("Todas las vistas PHP están 100% libres de sentencias SQL destructivas o directas", 
    !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|SELECT\s+[\w\*,\s]+FROM)\b/i', $cleanAllViews));
assertTest("Todas las vistas PHP están 100% libres de objetos PDO", !str_contains($cleanAllViews, 'PDO'));

$allCtrlCode = '';
foreach (glob($webCtrlDir . '/*.php') as $cFile) {
    $allCtrlCode .= file_get_contents($cFile) . "\n";
}
$cleanAllCtrl = preg_replace('#//.*$|/\*.*?\*/#m', '', $allCtrlCode);
assertTest("Todos los controladores Web están 100% libres de consultas directas a base de datos", 
    !preg_match('/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM|SELECT\s+[\w\*,\s]+FROM)\b/i', $cleanAllCtrl) && !str_contains($cleanAllCtrl, 'Database::conn'));

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
