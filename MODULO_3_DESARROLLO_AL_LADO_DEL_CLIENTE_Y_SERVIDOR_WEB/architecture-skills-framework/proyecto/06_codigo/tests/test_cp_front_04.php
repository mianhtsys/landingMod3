<?php
declare(strict_types=1);

// Test automatizado para CP-FRONT-04: Alta de categorías (S3IH)
// Valida formulario accesible, elementos ARIA, auditoría de cero SQL, integración con módulos JS y backend.

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-FRONT-04: ALTA DE CATEGORÍAS (S3IH)\n";
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
$runnerScript = __DIR__ . '/runner_front_alta_cat.php';

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
    $sessLoginRunner = __DIR__ . '/runner_temp_sess_front4.php';
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
    file_put_contents(__DIR__ . '/active_sess_front4.txt', session_id());
});
$router->dispatch(new Request());
PHP
    , $emailDemo, $passDemo));

    shell_exec("\"{$phpBinary}\" \"{$sessLoginRunner}\" 2>&1");
    @unlink($sessLoginRunner);
    $sid = trim((string)file_get_contents(__DIR__ . '/active_sess_front4.txt'));
    @unlink(__DIR__ . '/active_sess_front4.txt');
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
// 1. RENDERIZADO DEL FORMULARIO DE ALTA DE CATEGORÍAS
// ============================================================
echo "--- 1. Renderizado Accesible del Formulario de Alta ---\n";
$resView = callWebRoute('/categorias', $sessionId);
$html = $resView['output'];

assertTest("Sección de formulario presente (<section class=\"form-section\")", str_contains($html, '<section class="form-section"'));
assertTest("Sección vinculada con aria-labelledby a título (#cat-new-title)", str_contains($html, 'aria-labelledby="cat-new-title"'));
assertTest("Encabezado h2 'Nueva categoría' (#cat-new-title)", str_contains($html, '<h2 id="cat-new-title">Nueva categoría</h2>'));
assertTest("Formulario con novalidate (#cat-new-form)", str_contains($html, '<form id="cat-new-form" novalidate>'));
assertTest("Campo de nombre accesible (#cat-nombre, required, maxlength=100)", 
    str_contains($html, 'id="cat-nombre"') && str_contains($html, 'name="nombre"') && str_contains($html, 'required') && str_contains($html, 'maxlength="100"'));
assertTest("Etiqueta para nombre vinculada con for=\"cat-nombre\"", str_contains($html, '<label for="cat-nombre">'));
assertTest("Selector jerárquico opcional (#cat-padre, name=\"categoria_padre_id\")", 
    str_contains($html, '<select id="cat-padre" name="categoria_padre_id">'));
assertTest("Opción raíz predeterminada '— Ninguna —'", str_contains($html, '<option value="">— Ninguna —</option>'));
assertTest("Contenedor reactivo de errores con role=\"alert\" (#cat-new-error)", 
    str_contains($html, 'id="cat-new-error"') && str_contains($html, 'role="alert"') && str_contains($html, 'hidden'));
assertTest("Contenedor de éxito accesible con role=\"status\" (#cat-new-ok)", 
    str_contains($html, 'id="cat-new-ok"') && str_contains($html, 'role="status"') && str_contains($html, 'hidden'));
assertTest("Botón de envío para creación (#cat-new-submit)", 
    str_contains($html, 'id="cat-new-submit"') && str_contains($html, 'type="submit"') && str_contains($html, 'Crear categoría'));
assertTest("Botón de cancelación con estado oculto inicial (#cat-cancel-edit)", 
    str_contains($html, 'id="cat-cancel-edit"') && str_contains($html, 'hidden'));

// ============================================================
// 2. AUDITORÍA DE ARQUITECTURA: CERO SQL EN CAPA DE PRESENTACIÓN
// ============================================================
echo "\n--- 2. Auditoría de Arquitectura: Cero SQL en Presentación ---\n";
$viewCode = (string)file_get_contents(dirname(__DIR__) . '/app/Views/categorias/index.php');
$cleanView = strip_tags(preg_replace('#//.*$|/\*.*?\*/#m', '', $viewCode));

assertTest("La vista NO contiene sentencias SQL destructivas ni de inserción directa", 
    !preg_match('/\b(INSERT\s+INTO|SELECT\s+[\w\*,\s]+FROM|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $cleanView));
assertTest("La vista NO contiene referencias directas a objetos de persistencia PDO", 
    !str_contains($viewCode, 'PDO') && !str_contains($viewCode, 'Database::'));

// ============================================================
// 3. INTEGRIDAD DE MÓDULOS JAVASCRIPT (ES6+)
// ============================================================
echo "\n--- 3. Lógica JavaScript para Alta de Categorías ---\n";
$publicDir = dirname(__DIR__) . '/public';
$catJs = (string)file_get_contents($publicDir . '/assets/js/modules/categorias.js');
$apiJs = (string)file_get_contents($publicDir . '/assets/js/api/categorias-api.js');

assertTest("categorias.js importa createCategoria desde API", str_contains($catJs, 'createCategoria'));
assertTest("categorias.js intercepta submit del formulario", str_contains($catJs, "form?.addEventListener('submit'"));
assertTest("categorias.js valida obligatoriedad del nombre en cliente", str_contains($catJs, 'El nombre es obligatorio.'));
assertTest("categorias.js bloquea submitBtn.disabled = true para evitar doble envío", str_contains($catJs, 'submitBtn.disabled = true'));
assertTest("categorias.js emite mensaje 'Categoría creada.' en formOk", str_contains($catJs, "'Categoría creada.'"));
assertTest("categorias.js despliega errores de validación de API en formErr", str_contains($catJs, 'formErr.textContent = err.message'));
assertTest("categorias-api.js expone función createCategoria apuntando a POST /api/v1/categorias", 
    str_contains($apiJs, "export const createCategoria = (payload) => send('POST', '/api/v1/categorias', payload);"));

// ============================================================
// 4. PRUEBA FUNCIONAL E2E DEL ENDPOINT DE ALTA QUE CONSUME LA VISTA
// ============================================================
echo "\n--- 4. Integración Funcional con API REST (Backend Consumido) ---\n";
function callApiPost(string $uri, array $payload, ?string $sess = null): array
{
    global $phpBinary;
    $tmpF = __DIR__ . '/tmp_front4_api.json';
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
    $runner = __DIR__ . '/runner_tmp_front4_api.php';
    file_put_contents($runner, $code);
    $out = shell_exec("\"{$phpBinary}\" \"{$runner}\" 2>&1");
    @unlink($runner);
    @unlink($tmpF);
    return json_decode((string)$out, true) ?? [];
}

// Alta válida
$resApiOk = callApiPost('/api/v1/categorias', ['nombre' => 'Instrumental Quirúrgico Especializado'], $sessionId);
assertTest("API responde success: true ante alta válida enviada desde cliente", ($resApiOk['success'] ?? false) === true);
assertTest("API retorna el ID generado de la nueva categoría", ($resApiOk['data']['id'] ?? 0) > 0);
assertTest("API retorna estado 'activo' por defecto para nueva categoría", ($resApiOk['data']['estado'] ?? '') === 'activo');

// Alta con error de validación (nombre vacío)
$resApiFail = callApiPost('/api/v1/categorias', ['nombre' => '   '], $sessionId);
assertTest("API rechaza alta con nombre vacío retornando success: false", ($resApiFail['success'] ?? true) === false);
assertTest("API informa error descriptivo 'nombre: obligatorio'", str_contains($resApiFail['error'] ?? '', 'nombre: obligatorio'));

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
