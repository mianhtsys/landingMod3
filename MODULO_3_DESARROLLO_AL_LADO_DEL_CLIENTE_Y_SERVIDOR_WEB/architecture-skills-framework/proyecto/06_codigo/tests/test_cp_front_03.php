<?php
declare(strict_types=1);

// Test automatizado para CP-FRONT-03: Listado de categorías (S3IH)
// Valida control de acceso, renderizado HTML accesible, ausencia de SQL en vistas, integración con módulos JS.

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-FRONT-03: LISTADO DE CATEGORÍAS (S3IH)\n";
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
$runnerScript = __DIR__ . '/runner_front_categorias.php';

// Limpieza y preparación de usuario para simular sesión activa
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE auth_usuario");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

$emailDemo = envRequired('DEMO_USER_EMAIL');
$passDemo = envRequired('DEMO_USER_PASSWORD');
$hashDemo = password_hash($passDemo, PASSWORD_ARGON2ID);
$stmtUser = $pdo->prepare("
    INSERT INTO auth_usuario (id, nombre, email, hash_password, estado)
    VALUES (1, 'Lic. Mariana Gómez - Farmacia Central', :email, :hash, 'activo')
");
$stmtUser->execute(['email' => $emailDemo, 'hash' => $hashDemo]);

// Helper para iniciar sesión PHP y obtener cookie id
function getActiveSessionId(): string
{
    global $phpBinary, $emailDemo, $passDemo;
    $sessLoginRunner = __DIR__ . '/runner_temp_sess_front3.php';
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
    file_put_contents(__DIR__ . '/active_sess_front3.txt', session_id());
});
$router->dispatch(new Request());
PHP
    , $emailDemo, $passDemo));

    shell_exec("\"{$phpBinary}\" \"{$sessLoginRunner}\" 2>&1");
    @unlink($sessLoginRunner);
    $sid = trim((string)file_get_contents(__DIR__ . '/active_sess_front3.txt'));
    @unlink(__DIR__ . '/active_sess_front3.txt');
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
echo "--- 1. Control de Acceso (/categorias) ---\n";
$resAnon = callWebRoute('/categorias', null);
assertTest("Acceso sin sesión redirige (no renderiza contenido de categorías)", !str_contains($resAnon['output'], 'cat-tbody'));

$resAuth = callWebRoute('/categorias', $sessionId);
$html = $resAuth['output'];
assertTest("Acceso con sesión activa renderiza exitosamente", str_contains($html, 'cat-tbody'));

// ============================================================
// 2. ESTRUCTURA SEMÁNTICA Y NAVEGACIÓN ACTIVA
// ============================================================
echo "\n--- 2. Estructura Semántica y Navegación ---\n";
assertTest("Estructura base HTML5 (<!DOCTYPE html>)", str_contains($html, '<!DOCTYPE html>'));
assertTest("Título institucional en <title>", str_contains($html, 'Categorías') && str_contains($html, 'S3IH'));
assertTest("Skip-link accesible presente", str_contains($html, '<a class="skip-link" href="#contenido">'));
assertTest("Enlace /categorias con aria-current=\"page\" en sidebar", str_contains($html, '<a href="/categorias" aria-current="page">Categorías</a>'));
assertTest("Encabezado <h1> con título de categorías de suministros", str_contains($html, '<h1>Categorías de Suministros</h1>'));
assertTest("Nombre del usuario renderizado en el layout", str_contains($html, 'Lic. Mariana Gómez - Farmacia Central'));

// ============================================================
// 3. TABLA Y COMPONENTES REACTIVOS DE PRESENTACIÓN
// ============================================================
echo "\n--- 3. Elementos de Presentación y Tabla Accesible ---\n";
assertTest("Contenedor de error accesible con role=\"alert\" (#cat-error)", str_contains($html, 'id="cat-error"') && str_contains($html, 'role="alert"') && str_contains($html, 'hidden'));
assertTest("Indicador de estado de carga (#cat-loading)", str_contains($html, 'id="cat-loading"'));
assertTest("Indicador de catálogo vacío (#cat-empty)", str_contains($html, 'id="cat-empty"') && str_contains($html, 'hidden'));
assertTest("Envoltorio de tabla accesible (<div class=\"table-wrap\">)", str_contains($html, '<div class="table-wrap">'));
assertTest("Tabla semántica (<table id=\"cat-table\")", str_contains($html, '<table id="cat-table"'));
assertTest("Encabezados accesibles (<th scope=\"col\">)", str_contains($html, '<th scope="col">ID</th>') && str_contains($html, '<th scope="col">Nombre</th>'));
assertTest("Cuerpo de tabla con id 'cat-tbody'", str_contains($html, '<tbody id="cat-tbody"></tbody>'));
assertTest("Inclusión de módulo JS (/assets/js/modules/categorias.js)", str_contains($html, '<script type="module" src="/assets/js/modules/categorias.js"></script>'));

// ============================================================
// 4. AUDITORÍA DE ARQUITECTURA: CERO SQL EN CAPA DE PRESENTACIÓN
// ============================================================
echo "\n--- 4. Auditoría de Arquitectura: Cero SQL en Presentación ---\n";
$viewCode = (string)file_get_contents(dirname(__DIR__) . '/app/Views/categorias/index.php');
$controllerCode = (string)file_get_contents(dirname(__DIR__) . '/app/Controllers/Web/CategoriasController.php');

$cleanView = preg_replace('#//.*$|/\*.*?\*/#m', '', $viewCode);
$cleanController = preg_replace('#//.*$|/\*.*?\*/#m', '', $controllerCode);

// strip_tags elimina etiquetas HTML como <select id="cat-padre"> sin alterar código PHP o consultas de base de datos
$viewPhpContent = strip_tags($cleanView);

assertTest("Vista categorias/index.php NO contiene sentencias SQL (SELECT ..., INSERT INTO, etc.)", 
    !preg_match('/\b(SELECT\s+[\w\*,\s]+FROM|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $viewPhpContent));
assertTest("Vista categorias/index.php NO invoca PDO ni conexiones a base de datos", 
    !str_contains($cleanView, 'PDO') && !str_contains($cleanView, 'Database::'));
assertTest("CategoriasController NO contiene sentencias SQL ni llamadas a base de datos", 
    !preg_match('/\b(SELECT\s+[\w\*,\s]+FROM|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $cleanController) && !str_contains($cleanController, 'Database::'));

// ============================================================
// 5. INTEGRIDAD Y FUNCIONALIDAD DE MÓDULOS JAVASCRIPT
// ============================================================
echo "\n--- 5. Integridad de Módulos JavaScript ---\n";
$publicDir = dirname(__DIR__) . '/public';
$catJs = (string)file_get_contents($publicDir . '/assets/js/modules/categorias.js');
$apiJs = (string)file_get_contents($publicDir . '/assets/js/api/categorias-api.js');

assertTest("categorias.js importa listCategorias desde API", str_contains($catJs, 'listCategorias'));
assertTest("categorias.js incluye función de escape contra XSS (esc)", str_contains($catJs, 'function esc('));
assertTest("categorias.js aplica clases badge-ok y badge-off según estado", str_contains($catJs, 'badge badge-'));
assertTest("categorias.js interactúa con cat-tbody, cat-loading y cat-table", 
    str_contains($catJs, 'cat-tbody') && str_contains($catJs, 'cat-loading') && str_contains($catJs, 'cat-table'));
assertTest("categorias-api.js consume /api/v1/categorias con same-origin", 
    str_contains($apiJs, "'/api/v1/categorias'") && str_contains($apiJs, "credentials: 'same-origin'"));

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
