<?php
declare(strict_types=1);

// Test automatizado para CP-FRONT-08: Edición e Inactivación de productos (S3IH)
// Valida botones de acción, flujo de edición/cancelación, borrado lógico con confirmación, auditoría cero SQL e integración e2e.

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-FRONT-08: EDICIÓN E INACTIVACIÓN DE PRODUCTOS (S3IH)\n";
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
$runnerScript = __DIR__ . '/runner_front_edit_prod.php';

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

// Helper para iniciar sesión PHP y obtener cookie id
function getActiveSessionId(): string
{
    global $phpBinary, $emailDemo, $passDemo;
    $sessLoginRunner = __DIR__ . '/runner_temp_sess_front8.php';
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
    file_put_contents(__DIR__ . '/active_sess_front8.txt', session_id());
});
$router->dispatch(new Request());
PHP
    , $emailDemo, $passDemo));

    shell_exec("\"{$phpBinary}\" \"{$sessLoginRunner}\" 2>&1");
    @unlink($sessLoginRunner);
    $sid = trim((string)file_get_contents(__DIR__ . '/active_sess_front8.txt'));
    @unlink(__DIR__ . '/active_sess_front8.txt');
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
echo "--- 1. Elementos de Edición y Cancelación en Vista (/productos) ---\n";
$resView = callWebRoute('/productos', $sessionId);
$html = $resView['output'];

assertTest("Columna semántica de acciones (<th scope=\"col\">Acciones</th>)", str_contains($html, '<th scope="col">Acciones</th>'));
assertTest("Botón de cancelación de edición accesible (#prod-cancel-edit)", 
    str_contains($html, 'id="prod-cancel-edit"') && str_contains($html, 'Cancelar edición') && str_contains($html, 'hidden'));
assertTest("Título de formulario preparado para alternar edición/creación (#prod-new-title)", str_contains($html, 'id="prod-new-title"'));
assertTest("Botón de envío con id para alternar texto (#prod-new-submit)", str_contains($html, 'id="prod-new-submit"'));

// ============================================================
// 2. AUDITORÍA DE ARQUITECTURA: CERO SQL EN CAPA DE PRESENTACIÓN
// ============================================================
echo "\n--- 2. Auditoría de Arquitectura: Cero SQL en Presentación ---\n";
$viewCode = (string)file_get_contents(dirname(__DIR__) . '/app/Views/productos/index.php');
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
$prodJs = (string)file_get_contents($publicDir . '/assets/js/modules/productos.js');
$apiJs = (string)file_get_contents($publicDir . '/assets/js/api/productos-api.js');

assertTest("productos.js importa updateProducto y deleteProducto desde API", 
    str_contains($prodJs, 'updateProducto') && str_contains($prodJs, 'deleteProducto'));
assertTest("productos.js renderiza botón de edición con data-action=\"edit\"", str_contains($prodJs, 'data-action="edit"'));
assertTest("productos.js renderiza botón de inactivación solo para activos (data-action=\"deactivate\")", 
    str_contains($prodJs, 'data-action="deactivate"') && str_contains($prodJs, "r.estado !== 'activo'"));
assertTest("productos.js cambia texto de título a 'Editar producto'", str_contains($prodJs, "'Editar producto'"));
assertTest("productos.js cambia botón a 'Guardar cambios'", str_contains($prodJs, "'Guardar cambios'"));
assertTest("productos.js muestra botón cancelBtn al editar", str_contains($prodJs, 'cancelBtn.hidden = false'));
assertTest("productos.js incluye confirmación explícita antes de inactivar", str_contains($prodJs, 'window.confirm'));
assertTest("productos.js invoca updateProducto(editId, payload) en modo edición", str_contains($prodJs, 'await updateProducto(editId, payload)'));
assertTest("productos-api.js exporta updateProducto con método PUT", 
    str_contains($apiJs, 'send(\'PUT\', `/api/v1/productos/${id}`, payload)'));
assertTest("productos-api.js exporta deleteProducto con método DELETE", 
    str_contains($apiJs, 'send(\'DELETE\', `/api/v1/productos/${id}`)'));

// ============================================================
// 4. INTEGRACIÓN FUNCIONAL E2E (EDICIÓN, CONFLICTO SKU, BORRADO LÓGICO)
// ============================================================
echo "\n--- 4. Integración Funcional con Backend (API REST) ---\n";
function callApiMethodProd(string $method, string $uri, ?array $payload, ?string $sess = null): array
{
    global $phpBinary;
    $tmpF = __DIR__ . '/tmp_front8_api.json';
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
    $runner = __DIR__ . '/runner_tmp_front8_api.php';
    file_put_contents($runner, $code);
    $out = shell_exec("\"{$phpBinary}\" \"{$runner}\" 2>&1");
    @unlink($runner);
    if ($payload !== null) @unlink($tmpF);
    return json_decode((string)$out, true) ?? [];
}

// 4.1 Crear categoría y productos iniciales para pruebas
$resCat = callApiMethodProd('POST', '/api/v1/categorias', ['nombre' => 'Equipamiento Biomédico'], $sessionId);
$catId = (int)($resCat['data']['id'] ?? 0);

$resP1 = callApiMethodProd('POST', '/api/v1/productos', [
    'sku' => 'BIO-MON-001',
    'nombre' => 'Monitor Multiparámetro Básico',
    'categoria_id' => $catId,
    'unidad' => 'EQUIPO'
], $sessionId);
$prodId1 = (int)($resP1['data']['id'] ?? 0);

$resP2 = callApiMethodProd('POST', '/api/v1/productos', [
    'sku' => 'BIO-DESF-002',
    'nombre' => 'Desfibrilador Manual UCI',
    'categoria_id' => $catId,
    'unidad' => 'EQUIPO'
], $sessionId);
$prodId2 = (int)($resP2['data']['id'] ?? 0);

assertTest("Productos iniciales creados para pruebas", $prodId1 > 0 && $prodId2 > 0);

// 4.2 Actualización válida (PUT /api/v1/productos/{id})
$resUpdate = callApiMethodProd('PUT', "/api/v1/productos/{$prodId1}", [
    'sku' => 'BIO-MON-001',
    'nombre' => 'Monitor Multiparámetro Avanzado con Capnografía',
    'categoria_id' => $catId,
    'unidad' => 'EQUIPO'
], $sessionId);
assertTest("PUT /api/v1/productos/{id} actualiza exitosamente (200 OK)", ($resUpdate['success'] ?? false) === true);
assertTest("Nombre actualizado reflejado en la respuesta", 
    ($resUpdate['data']['nombre'] ?? '') === 'Monitor Multiparámetro Avanzado con Capnografía');

// 4.3 Conflicto por SKU repetido (PUT con el SKU del producto 2)
$resDupSku = callApiMethodProd('PUT', "/api/v1/productos/{$prodId1}", [
    'sku' => 'BIO-DESF-002', // SKU perteneciente a prodId2
    'nombre' => 'Intento de colisión',
    'categoria_id' => $catId,
    'unidad' => 'EQUIPO'
], $sessionId);
assertTest("PUT con SKU duplicado de otro producto retorna error 409", ($resDupSku['success'] ?? true) === false);
assertTest("Informa error 409 'sku ya existe'", str_contains($resDupSku['error'] ?? '', 'sku ya existe'));

// 4.4 Inactivación lógica (DELETE /api/v1/productos/{id})
$resDel = callApiMethodProd('DELETE', "/api/v1/productos/{$prodId1}", null, $sessionId);
assertTest("DELETE /api/v1/productos/{id} ejecuta inactivación lógica (200 OK)", ($resDel['success'] ?? false) === true);
assertTest("Estado retornado es 'inactivo'", ($resDel['data']['estado'] ?? '') === 'inactivo');

// 4.5 Verificación de persistencia en base de datos (RN-012 Zero-Physical-Delete)
$stmtDb = $pdo->prepare("SELECT COUNT(*) as total, estado FROM cat_producto WHERE id = :id GROUP BY estado");
$stmtDb->execute(['id' => $prodId1]);
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
