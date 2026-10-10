<?php
declare(strict_types=1);

// Test automatizado para CP-FRONT-05: Edición e Inactivación de categorías (S3IH)
// Valida botones de acción, flujo de edición/cancelación, borrado lógico con confirmación, auditoría cero SQL e integración e2e.

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-FRONT-05: EDICIÓN E INACTIVACIÓN DE CATEGORÍAS (S3IH)\n";
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
$runnerScript = __DIR__ . '/runner_front_edit_cat.php';

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
    VALUES (1, 'Lic. Mariana Gómez - Farmacia Central', :email, :hash, 'activo')
");
$stmtUser->execute(['email' => $emailDemo, 'hash' => $hashDemo]);

// Helper para iniciar sesión PHP y obtener cookie id
function getActiveSessionId(): string
{
    global $phpBinary, $emailDemo, $passDemo;
    $sessLoginRunner = __DIR__ . '/runner_temp_sess_front5.php';
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
    file_put_contents(__DIR__ . '/active_sess_front5.txt', session_id());
});
$router->dispatch(new Request());
PHP
    , $emailDemo, $passDemo));

    shell_exec("\"{$phpBinary}\" \"{$sessLoginRunner}\" 2>&1");
    @unlink($sessLoginRunner);
    $sid = trim((string)file_get_contents(__DIR__ . '/active_sess_front5.txt'));
    @unlink(__DIR__ . '/active_sess_front5.txt');
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
// 1. RENDERIZADO DE ELEMENTOS DE EDICIÓN Y CANCELACIÓN EN VISTA
// ============================================================
echo "--- 1. Elementos de Edición y Cancelación en Vista (/categorias) ---\n";
$resView = callWebRoute('/categorias', $sessionId);
$html = $resView['output'];

assertTest("Columna semántica de acciones (<th scope=\"col\">Acciones</th>)", str_contains($html, '<th scope="col">Acciones</th>'));
assertTest("Botón de cancelación de edición accesible (#cat-cancel-edit)", 
    str_contains($html, 'id="cat-cancel-edit"') && str_contains($html, 'Cancelar edición') && str_contains($html, 'hidden'));
assertTest("Título de formulario preparado para alternar edición/creación (#cat-new-title)", str_contains($html, 'id="cat-new-title"'));
assertTest("Botón de envío con id para alternar texto (#cat-new-submit)", str_contains($html, 'id="cat-new-submit"'));

// ============================================================
// 2. AUDITORÍA DE ARQUITECTURA: CERO SQL EN CAPA DE PRESENTACIÓN
// ============================================================
echo "\n--- 2. Auditoría de Arquitectura: Cero SQL en Presentación ---\n";
$viewCode = (string)file_get_contents(dirname(__DIR__) . '/app/Views/categorias/index.php');
$cleanView = strip_tags(preg_replace('#//.*$|/\*.*?\*/#m', '', $viewCode));

assertTest("La vista NO contiene sentencias SQL destructivas ni de actualización directa", 
    !preg_match('/\b(UPDATE\s+\w+\s+SET|DELETE\s+FROM|INSERT\s+INTO|SELECT\s+[\w\*,\s]+FROM)\b/i', $cleanView));
assertTest("La vista NO contiene referencias a objetos de persistencia PDO ni Database::", 
    !str_contains($viewCode, 'PDO') && !str_contains($viewCode, 'Database::'));

// ============================================================
// 3. INTEGRIDAD DE MÓDULOS JAVASCRIPT (ES6+)
// ============================================================
echo "\n--- 3. Lógica JavaScript para Edición e Inactivación ---\n";
$publicDir = dirname(__DIR__) . '/public';
$catJs = (string)file_get_contents($publicDir . '/assets/js/modules/categorias.js');
$apiJs = (string)file_get_contents($publicDir . '/assets/js/api/categorias-api.js');

assertTest("categorias.js importa updateCategoria y deleteCategoria desde API", 
    str_contains($catJs, 'updateCategoria') && str_contains($catJs, 'deleteCategoria'));
assertTest("categorias.js renderiza botón de edición con data-action=\"edit\"", str_contains($catJs, 'data-action="edit"'));
assertTest("categorias.js renderiza botón de inactivación solo para activos (data-action=\"deactivate\")", 
    str_contains($catJs, 'data-action="deactivate"') && str_contains($catJs, "r.estado !== 'activo'"));
assertTest("categorias.js cambia texto de título a 'Editar categoría'", str_contains($catJs, "'Editar categoría'"));
assertTest("categorias.js cambia botón a 'Guardar cambios'", str_contains($catJs, "'Guardar cambios'"));
assertTest("categorias.js muestra botón cancelBtn al editar", str_contains($catJs, 'cancelBtn.hidden = false'));
assertTest("categorias.js excluye el propio ID en select padre (fillParentSelect)", str_contains($catJs, 'fillParentSelect(id)'));
assertTest("categorias.js incluye confirmación explícita antes de inactivar", str_contains($catJs, 'window.confirm'));
assertTest("categorias.js invoca updateCategoria(editId, payload) en modo edición", str_contains($catJs, 'await updateCategoria(editId, payload)'));
assertTest("categorias-api.js exporta updateCategoria con método PUT", 
    str_contains($apiJs, 'send(\'PUT\', `/api/v1/categorias/${id}`, payload)'));
assertTest("categorias-api.js exporta deleteCategoria con método DELETE", 
    str_contains($apiJs, 'send(\'DELETE\', `/api/v1/categorias/${id}`)'));

// ============================================================
// 4. INTEGRACIÓN FUNCIONAL E2E (EDICIÓN, AUTO-PADRE, BORRADO LÓGICO)
// ============================================================
echo "\n--- 4. Integración Funcional con Backend (API REST) ---\n";
function callApiMethod(string $method, string $uri, ?array $payload, ?string $sess = null): array
{
    global $phpBinary;
    $tmpF = __DIR__ . '/tmp_front5_api.json';
    if ($payload !== null) {
        file_put_contents($tmpF, json_encode($payload));
    }
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
$p = '%s';
Request::$testInput = ($p !== '' && file_exists($p)) ? file_get_contents($p) : null;
$router = new Router();
foreach (require dirname(__DIR__) . '/routes/api.php' as [$m, $pattern, $handler]) {
    $router->add($m, $pattern, $handler);
}
$router->dispatch(new Request());
PHP
    , $method, $uri, $sess ?? '', $payload !== null ? addcslashes($tmpF, "'\\") : '');
    $runner = __DIR__ . '/runner_tmp_front5_api.php';
    file_put_contents($runner, $code);
    $out = shell_exec("\"{$phpBinary}\" \"{$runner}\" 2>&1");
    @unlink($runner);
    if ($payload !== null) @unlink($tmpF);
    return json_decode((string)$out, true) ?? [];
}

// 4.1 Crear categoría inicial
$resCrear = callApiMethod('POST', '/api/v1/categorias', ['nombre' => 'Monitores de Signos Vitales'], $sessionId);
$catId = (int)($resCrear['data']['id'] ?? 0);
assertTest("Categoría creada con éxito para prueba (ID generado)", $catId > 0);

// 4.2 Actualización válida (PUT)
$resUpdate = callApiMethod('PUT', "/api/v1/categorias/{$catId}", [
    'nombre' => 'Monitores de Signos Vitales Avanzados UCI',
    'categoria_padre_id' => null
], $sessionId);
assertTest("PUT /api/v1/categorias/{id} actualiza exitosamente (200 OK)", ($resUpdate['success'] ?? false) === true);
assertTest("Nombre actualizado reflejado en la respuesta", ($resUpdate['data']['nombre'] ?? '') === 'Monitores de Signos Vitales Avanzados UCI');

// 4.3 Regla de negocio: Prevención de auto-padre (PUT con categoria_padre_id == id)
$resAutoPadre = callApiMethod('PUT', "/api/v1/categorias/{$catId}", [
    'nombre' => 'Intento Auto-Padre',
    'categoria_padre_id' => $catId
], $sessionId);
assertTest("PUT con categoría como padre de sí misma retorna error 400", ($resAutoPadre['success'] ?? true) === false);
assertTest("Informa regla anti-cíclica auto-padre", str_contains($resAutoPadre['error'] ?? '', 'auto-padre'));

// 4.4 Inactivación lógica (DELETE)
$resDel = callApiMethod('DELETE', "/api/v1/categorias/{$catId}", null, $sessionId);
assertTest("DELETE /api/v1/categorias/{id} ejecuta inactivación lógica (200 OK)", ($resDel['success'] ?? false) === true);
assertTest("Estado retornado es 'inactivo'", ($resDel['data']['estado'] ?? '') === 'inactivo');

// 4.5 Verificación de persistencia en base de datos (RN-012 Zero-Physical-Delete)
$stmtDb = $pdo->prepare("SELECT COUNT(*) as total, estado FROM cat_categoria WHERE id = :id GROUP BY estado");
$stmtDb->execute(['id' => $catId]);
$rowDb = $stmtDb->fetch();
assertTest("El registro persiste en BD (COUNT=1, sin DELETE FROM físico)", ($rowDb['total'] ?? 0) == 1);
assertTest("El estado en BD es estrictamente 'inactivo'", ($rowDb['estado'] ?? '') === 'inactivo');

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
