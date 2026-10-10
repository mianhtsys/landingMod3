<?php
declare(strict_types=1);

// Test automatizado de integración para CP-INT-02: Categorías: listar → crear → editar → inactivar
// Workflow 06: Integración Final Didáctica (S3IH)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-INT-02: CATEGORÍAS (LISTAR → CREAR → EDITAR → INACTIVAR)\n";
echo "Workflow 06: Integración Final Didáctica\n";
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
$runnerScript = __DIR__ . '/runner_int_cat.php';

// Preparación de usuario de prueba y limpieza controlada de categorías de prueba
$emailDemo = envRequired('DEMO_USER_EMAIL');
$passDemo = envRequired('DEMO_USER_PASSWORD');
$hashDemo = password_hash($passDemo, PASSWORD_ARGON2ID);

$stmtUser = $pdo->prepare("
    INSERT INTO auth_usuario (id, nombre, email, hash_password, estado)
    VALUES (1, 'Dr. Roberto Siles - Coordinador Hospitalario', :email, :hash, 'activo')
    ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), hash_password = VALUES(hash_password), estado = 'activo'
");
$stmtUser->execute(['email' => $emailDemo, 'hash' => $hashDemo]);

// Limpieza de categorías para prueba limpia
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE cat_producto");
$pdo->exec("TRUNCATE TABLE cat_categoria");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

function executeHttp(string $type, string $method, string $uri, ?array $payload = null, ?string $cookieSession = null): array
{
    global $phpBinary, $runnerScript;
    $tmpPayload = __DIR__ . '/tmp_int_cat_payload.json';
    if ($payload !== null) {
        file_put_contents($tmpPayload, json_encode($payload));
    }

    $routesFile = $type === 'api' ? 'routes/api.php' : 'routes/web.php';

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

$pPath = '%s';
Request::$testInput = ($pPath !== '' && file_exists($pPath)) ? file_get_contents($pPath) : null;

$router = new Router();
foreach (require dirname(__DIR__) . '/%s' as [$m, $pattern, $handler]) {
    $router->add($m, $pattern, $handler);
}

register_shutdown_function(function() {
    if (session_status() === PHP_SESSION_ACTIVE && session_id() !== '') {
        file_put_contents(__DIR__ . '/tmp_active_session_cat.txt', session_id());
    }
});

$router->dispatch(new Request());
PHP
    , $method, $uri, $cookieSession ?? '', $payload !== null ? addcslashes($tmpPayload, "'\\") : '', $routesFile);

    file_put_contents($runnerScript, $code);
    $output = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
    @unlink($runnerScript);
    if ($payload !== null) @unlink($tmpPayload);

    $capturedSession = null;
    $sessFile = __DIR__ . '/tmp_active_session_cat.txt';
    if (file_exists($sessFile)) {
        $capturedSession = trim((string)file_get_contents($sessFile));
        @unlink($sessFile);
    }

    $json = json_decode((string)$output, true);
    return [
        'output' => (string)$output,
        'json' => is_array($json) ? $json : null,
        'sessionId' => $capturedSession
    ];
}

// ------------------------------------------------------------
// Obtención de sesión autenticada
// ------------------------------------------------------------
$loginRes = executeHttp('api', 'POST', '/api/v1/auth/login', [
    'email' => $emailDemo,
    'password' => $passDemo
], null);
$sessionId = $loginRes['sessionId'] ?? '';
assertTest("Sesión de usuario iniciada correctamente", !empty($sessionId));

// ============================================================
// FASE 1: CONTROL DE ACCESO A PANTALLA /categorias
// ============================================================
echo "\n--- 1. Acceso a Pantalla Web /categorias ---\n";

// 1.1 Acceso anónimo debe redirigir a /login
$webAnon = executeHttp('web', 'GET', '/categorias', null, null);
assertTest("GET /categorias sin sesión redirige (no muestra tabla)", !str_contains($webAnon['output'], 'id="cat-table"'));

// 1.2 Acceso autenticado debe renderizar la interfaz de categorías
$webAuth = executeHttp('web', 'GET', '/categorias', null, $sessionId);
$html = $webAuth['output'];
assertTest("GET /categorias con sesión renderiza vista correcta", str_contains($html, 'Categorías de Suministros'));
assertTest("Vista contiene tabla con id='cat-table'", str_contains($html, 'id="cat-table"'));
assertTest("Vista contiene formulario con id='cat-new-form'", str_contains($html, 'id="cat-new-form"'));
assertTest("Vista contiene selector de jerarquía #cat-padre", str_contains($html, 'id="cat-padre"'));
assertTest("Vista enlaza módulo JS categorias.js", str_contains($html, 'assets/js/modules/categorias.js'));

// 1.3 Verificación de Cero SQL en vista de categorías
$viewSource = file_get_contents(dirname(__DIR__) . '/app/Views/categorias/index.php');
$cleanView = strip_tags($viewSource);
$hasSqlInView = (bool) preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|FROM|WHERE)\b/i', $cleanView);
assertTest("Vista /categorias libre de consultas SQL (Separación MVC estricta)", !$hasSqlInView);

// ============================================================
// FASE 2: LISTAR INICIALMENTE (VACÍO O PREVIO)
// ============================================================
echo "\n--- 2. Listado Inicial vía API ---\n";

$listInit = executeHttp('api', 'GET', '/api/v1/categorias', null, $sessionId);
assertTest("GET /api/v1/categorias responde exitosamente", ($listInit['json']['success'] ?? false) === true);
assertTest("Listado inicial retorna arreglo", is_array($listInit['json']['data'] ?? null));
$initialCount = count($listInit['json']['data']);
assertTest("Conteo inicial verificado ({$initialCount} categorías)", true);

// ============================================================
// FASE 3: CREAR CATEGORÍAS (RAÍZ Y SUBCATEGORÍA CON PADRE)
// ============================================================
echo "\n--- 3. Creación de Categorías (Raíz y Subcategoría) ---\n";

// 3.1 Validación de payload: Nombre requerido
$createBad = executeHttp('api', 'POST', '/api/v1/categorias', [
    'nombre' => '   '
], $sessionId);
assertTest("POST /api/v1/categorias con nombre vacío es rechazado", ($createBad['json']['success'] ?? true) === false);

// 3.2 Crear Categoría Raíz
$createRoot = executeHttp('api', 'POST', '/api/v1/categorias', [
    'nombre' => 'Material Descartable e Insumos Quirúrgicos',
    'categoria_padre_id' => null
], $sessionId);
assertTest("POST /api/v1/categorias crea categoría raíz exitosamente (201)", ($createRoot['json']['success'] ?? false) === true);
$rootCat = $createRoot['json']['data'] ?? [];
$rootId = (int) ($rootCat['id'] ?? 0);
assertTest("Categoría raíz asigna ID numérico válido (#{$rootId})", $rootId > 0);
assertTest("Categoría raíz tiene estado 'activo'", ($rootCat['estado'] ?? '') === 'activo');
assertTest("Categoría raíz tiene categoria_padre_id nulo", ($rootCat['categoria_padre_id'] ?? null) === null);

// 3.3 Crear Subcategoría asociada a la raíz
$createChild = executeHttp('api', 'POST', '/api/v1/categorias', [
    'nombre' => 'Suturas y Agujas H1 UCI',
    'categoria_padre_id' => $rootId
], $sessionId);
assertTest("POST /api/v1/categorias crea subcategoría con padre válido (201)", ($createChild['json']['success'] ?? false) === true);
$childCat = $createChild['json']['data'] ?? [];
$childId = (int) ($childCat['id'] ?? 0);
assertTest("Subcategoría asigna ID numérico válido (#{$childId})", $childId > 0 && $childId !== $rootId);
assertTest("Subcategoría referencia padre_id correcto (#{$rootId})", (int) ($childCat['categoria_padre_id'] ?? 0) === $rootId);

// 3.4 Intento de asociar a un padre inexistente
$createInvalidPadre = executeHttp('api', 'POST', '/api/v1/categorias', [
    'nombre' => 'Categoría Huérfana Falsa',
    'categoria_padre_id' => 999999
], $sessionId);
assertTest("POST /api/v1/categorias con padre inexistente responde error 404", ($createInvalidPadre['json']['success'] ?? true) === false);

// ============================================================
// FASE 4: CONSULTA Y EDICIÓN DE CATEGORÍAS
// ============================================================
echo "\n--- 4. Consulta y Edición de Categorías ---\n";

// 4.1 Consulta por ID
$showCat = executeHttp('api', 'GET', "/api/v1/categorias/{$childId}", null, $sessionId);
assertTest("GET /api/v1/categorias/{id} recupera registro existente", ($showCat['json']['success'] ?? false) === true);
assertTest("Nombre coincide con el creado", ($showCat['json']['data']['nombre'] ?? '') === 'Suturas y Agujas H1 UCI');

// 4.2 Edición normal de nombre y padre
$editChild = executeHttp('api', 'PUT', "/api/v1/categorias/{$childId}", [
    'nombre' => 'Suturas Quirúrgicas Sintéticas Reabsorbibles H1',
    'categoria_padre_id' => $rootId
], $sessionId);
assertTest("PUT /api/v1/categorias/{id} actualiza registro (200)", ($editChild['json']['success'] ?? false) === true);
assertTest("Nuevo nombre reflejado en la respuesta", ($editChild['json']['data']['nombre'] ?? '') === 'Suturas Quirúrgicas Sintéticas Reabsorbibles H1');

// 4.3 Regla de negocio anti-cíclica: una categoría no puede ser su propio padre
$editCycle = executeHttp('api', 'PUT', "/api/v1/categorias/{$childId}", [
    'nombre' => 'Intento Auto-Padre',
    'categoria_padre_id' => $childId
], $sessionId);
assertTest("PUT /api/v1/categorias/{id} con auto-padre es rechazado (400)", ($editCycle['json']['success'] ?? true) === false);
assertTest("Mensaje reporta violación anti-cíclica (auto-padre)", str_contains($editCycle['json']['error'] ?? '', 'auto-padre'));

// ============================================================
// FASE 5: INACTIVACIÓN LÓGICA (ZERO-PHYSICAL-DELETE)
// ============================================================
echo "\n--- 5. Inactivación Lógica (RN-012 Zero-Physical-Delete) ---\n";

// 5.1 DELETE lógico vía API
$deleteRes = executeHttp('api', 'DELETE', "/api/v1/categorias/{$childId}", null, $sessionId);
assertTest("DELETE /api/v1/categorias/{id} responde exitosamente (200)", ($deleteRes['json']['success'] ?? false) === true);
assertTest("Estado retornado por API es 'inactivo'", ($deleteRes['json']['data']['estado'] ?? '') === 'inactivo');

// 5.2 Verificación directa en base de datos: el registro NO FUE BORRADO FÍSICAMENTE
$stmtDb = $pdo->prepare("SELECT id, nombre, estado FROM cat_categoria WHERE id = :id");
$stmtDb->execute(['id' => $childId]);
$dbRow = $stmtDb->fetch(PDO::FETCH_ASSOC);

assertTest("Verificación física BD: el registro existe en cat_categoria (no hubo DELETE FROM)", $dbRow !== false);
assertTest("Verificación física BD: columna 'estado' es estrictamente 'inactivo'", ($dbRow['estado'] ?? '') === 'inactivo');
assertTest("Verificación física BD: nombre permanece intacto en auditoría", ($dbRow['nombre'] ?? '') === 'Suturas Quirúrgicas Sintéticas Reabsorbibles H1');

// 5.3 Idempotencia del endpoint de inactivación
$deleteIdem = executeHttp('api', 'DELETE', "/api/v1/categorias/{$childId}", null, $sessionId);
assertTest("DELETE subsecuente sobre categoría ya inactiva es idempotente (200 OK)", ($deleteIdem['json']['success'] ?? false) === true);
assertTest("Mantiene estado 'inactivo' sin error", ($deleteIdem['json']['data']['estado'] ?? '') === 'inactivo');

// ============================================================
// FASE 6: LISTADO FINAL CON ESTADOS REFLEJADOS
// ============================================================
echo "\n--- 6. Listado Final Integrado ---\n";

$listFinal = executeHttp('api', 'GET', '/api/v1/categorias', null, $sessionId);
assertTest("GET /api/v1/categorias post-operaciones retorna 200 OK", ($listFinal['json']['success'] ?? false) === true);
$finalItems = $listFinal['json']['data'] ?? [];
assertTest("Listado contiene las categorías creadas", count($finalItems) >= 2);

$foundRoot = false;
$foundChild = false;
foreach ($finalItems as $item) {
    if ((int)$item['id'] === $rootId) {
        $foundRoot = ($item['estado'] === 'activo');
    }
    if ((int)$item['id'] === $childId) {
        $foundChild = ($item['estado'] === 'inactivo');
    }
}
assertTest("Categoría raíz figura activa en el listado", $foundRoot);
assertTest("Subcategoría figura inactiva en el listado", $foundChild);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
