<?php
declare(strict_types=1);

// Test automatizado de integración para CP-INT-03: Productos: listar → crear con categoría → editar → inactivar
// Workflow 06: Integración Final Didáctica (S3IH)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-INT-03: PRODUCTOS (LISTAR → CREAR CON CATEGORÍA → EDITAR → INACTIVAR)\n";
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
$runnerScript = __DIR__ . '/runner_int_prod.php';

// Preparación de usuario de prueba y credenciales demo
$emailDemo = envRequired('DEMO_USER_EMAIL');
$passDemo = envRequired('DEMO_USER_PASSWORD');
$hashDemo = password_hash($passDemo, PASSWORD_ARGON2ID);

$stmtUser = $pdo->prepare("
    INSERT INTO auth_usuario (id, nombre, email, hash_password, estado)
    VALUES (1, 'Lic. Carlos Méndez - Farmacia Central H1', :email, :hash, 'activo')
    ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), hash_password = VALUES(hash_password), estado = 'activo'
");
$stmtUser->execute(['email' => $emailDemo, 'hash' => $hashDemo]);

// Limpieza de tablas para ejecución determinista
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE cat_producto");
$pdo->exec("TRUNCATE TABLE cat_categoria");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// Semilla de categorías requeridas para asociar productos
$stmtCat = $pdo->prepare("
    INSERT INTO cat_categoria (id, nombre, categoria_padre_id, estado, user_create, user_update, user_created_at, user_update_at)
    VALUES 
    (1, 'Equipamiento Biomédico y Monitorización', NULL, 'activo', 1, 1, NOW(), NOW()),
    (2, 'Insumos Farmacéuticos Hospitalarios', NULL, 'activo', 1, 1, NOW(), NOW())
");
$stmtCat->execute();

function executeHttp(string $type, string $method, string $uri, ?array $payload = null, ?string $cookieSession = null): array
{
    global $phpBinary, $runnerScript;
    $tmpPayload = __DIR__ . '/tmp_int_prod_payload.json';
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
        file_put_contents(__DIR__ . '/tmp_active_session_prod.txt', session_id());
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
    $sessFile = __DIR__ . '/tmp_active_session_prod.txt';
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
// FASE 1: CONTROL DE ACCESO (WEB Y API ANÓNIMA)
// ============================================================
echo "\n--- 1. Control de Acceso y Protección de Rutas ---\n";

// 1.1 Web: Acceso anónimo a /productos redirige a /login
$webAnon = executeHttp('web', 'GET', '/productos', null, null);
assertTest("GET /productos sin sesión redirige (no emite tabla)", !str_contains($webAnon['output'], 'id="prod-table"'));

// 1.2 Web: Acceso con sesión renderiza pantalla de productos
$webAuth = executeHttp('web', 'GET', '/productos', null, $sessionId);
$html = $webAuth['output'];
assertTest("GET /productos con sesión renderiza vista de catálogo", str_contains($html, 'Catálogo de Productos y Equipos'));
assertTest("Vista contiene tabla con id='prod-table'", str_contains($html, 'id="prod-table"'));
assertTest("Vista contiene formulario con id='prod-new-form'", str_contains($html, 'id="prod-new-form"'));
assertTest("Vista contiene selector de categorías #prod-cat", str_contains($html, 'id="prod-cat"'));
assertTest("Vista enlaza módulo JS productos.js", str_contains($html, 'assets/js/modules/productos.js'));

// 1.3 Cero SQL en vista de productos
$viewSource = file_get_contents(dirname(__DIR__) . '/app/Views/productos/index.php');
$cleanView = strip_tags($viewSource);
$hasSqlInView = (bool) preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|FROM|WHERE)\b/i', $cleanView);
assertTest("Vista /productos libre de consultas SQL (Separación MVC)", !$hasSqlInView);

// 1.4 API: Rutas protegidas contra acceso anónimo
$apiAnonGet = executeHttp('api', 'GET', '/api/v1/productos', null, null);
assertTest("GET /api/v1/productos sin sesión retorna 401 No autenticado", ($apiAnonGet['json']['error'] ?? '') === 'No autenticado');

$apiAnonPost = executeHttp('api', 'POST', '/api/v1/productos', [
    'sku' => 'ANON-001', 'nombre' => 'Test', 'categoria_id' => 1, 'unidad' => 'U'
], null);
assertTest("POST /api/v1/productos sin sesión retorna 401 No autenticado", ($apiAnonPost['json']['error'] ?? '') === 'No autenticado');

// ============================================================
// FASE 2: LISTADO INICIAL VÍA API
// ============================================================
echo "\n--- 2. Listado Inicial de Productos ---\n";

$listInit = executeHttp('api', 'GET', '/api/v1/productos', null, $sessionId);
assertTest("GET /api/v1/productos con sesión responde 200 OK", ($listInit['json']['success'] ?? false) === true);
assertTest("Listado inicial retorna arreglo", is_array($listInit['json']['data'] ?? null));
assertTest("Conteo inicial es 0 productos", count($listInit['json']['data']) === 0);

// ============================================================
// FASE 3: CREAR PRODUCTOS CON CATEGORÍA ASIGNADA
// ============================================================
echo "\n--- 3. Creación de Productos con Categoría Asignada ---\n";

// 3.1 Validación de campos obligatorios
$createMissingSku = executeHttp('api', 'POST', '/api/v1/productos', [
    'sku' => '', 'nombre' => 'Monitor', 'categoria_id' => 1, 'unidad' => 'EQUIPO'
], $sessionId);
assertTest("POST /api/v1/productos rechaza SKU vacío (400)", ($createMissingSku['json']['success'] ?? true) === false);

$createMissingCat = executeHttp('api', 'POST', '/api/v1/productos', [
    'sku' => 'BIO-MON-001', 'nombre' => 'Monitor', 'categoria_id' => 0, 'unidad' => 'EQUIPO'
], $sessionId);
assertTest("POST /api/v1/productos rechaza categoria_id inválida (400)", ($createMissingCat['json']['success'] ?? true) === false);

// 3.2 Validación referencial: Categoría inexistente
$createNonExistentCat = executeHttp('api', 'POST', '/api/v1/productos', [
    'sku' => 'BIO-MON-001', 'nombre' => 'Monitor', 'categoria_id' => 999999, 'unidad' => 'EQUIPO'
], $sessionId);
assertTest("POST /api/v1/productos con categoria_id inexistente responde 404", ($createNonExistentCat['json']['success'] ?? true) === false);
assertTest("Error especifica que categoria_id no existe", str_contains($createNonExistentCat['json']['error'] ?? '', 'categoria_id no existe'));

// 3.3 Creación exitosa de Producto 1 (Monitor UCI) vinculado a Categoría 1
$createProd1 = executeHttp('api', 'POST', '/api/v1/productos', [
    'sku' => 'BIO-MON-001',
    'nombre' => 'Monitor Multiparamétrico UCI H1',
    'categoria_id' => 1,
    'unidad' => 'EQUIPO'
], $sessionId);
assertTest("POST /api/v1/productos crea Producto 1 exitosamente (201)", ($createProd1['json']['success'] ?? false) === true);
$prod1 = $createProd1['json']['data'] ?? [];
$prod1Id = (int) ($prod1['id'] ?? 0);
assertTest("Producto 1 asigna ID válido (#{$prod1Id})", $prod1Id > 0);
assertTest("Producto 1 tiene SKU correcto (BIO-MON-001)", ($prod1['sku'] ?? '') === 'BIO-MON-001');
assertTest("Producto 1 tiene categoría vinculada (#1)", (int) ($prod1['categoria_id'] ?? 0) === 1);
assertTest("Producto 1 tiene estado 'activo'", ($prod1['estado'] ?? '') === 'activo');

// 3.4 Creación exitosa de Producto 2 (Bomba de Infusión) vinculado a Categoría 1
$createProd2 = executeHttp('api', 'POST', '/api/v1/productos', [
    'sku' => 'BIO-BOM-002',
    'nombre' => 'Bomba de Infusión Volumétrica Peristáltica H1',
    'categoria_id' => 1,
    'unidad' => 'UNIDAD'
], $sessionId);
assertTest("POST /api/v1/productos crea Producto 2 exitosamente (201)", ($createProd2['json']['success'] ?? false) === true);
$prod2 = $createProd2['json']['data'] ?? [];
$prod2Id = (int) ($prod2['id'] ?? 0);
assertTest("Producto 2 asigna ID válido (#{$prod2Id})", $prod2Id > 0 && $prod2Id !== $prod1Id);

// 3.5 Conflicto de unicidad de SKU
$createDupSku = executeHttp('api', 'POST', '/api/v1/productos', [
    'sku' => 'BIO-MON-001', // Ya existe
    'nombre' => 'Monitor Duplicado Intento',
    'categoria_id' => 1,
    'unidad' => 'EQUIPO'
], $sessionId);
assertTest("POST /api/v1/productos rechaza SKU duplicado (409 Conflict)", ($createDupSku['json']['success'] ?? true) === false);
assertTest("Mensaje reporta conflicto de SKU", str_contains($createDupSku['json']['error'] ?? '', 'sku ya existe'));

// ============================================================
// FASE 4: CONSULTA Y EDICIÓN DE PRODUCTOS
// ============================================================
echo "\n--- 4. Consulta y Edición de Productos ---\n";

// 4.1 Consulta por ID
$showProd = executeHttp('api', 'GET', "/api/v1/productos/{$prod1Id}", null, $sessionId);
assertTest("GET /api/v1/productos/{id} recupera el producto", ($showProd['json']['success'] ?? false) === true);
assertTest("Nombre coincide exactamente", ($showProd['json']['data']['nombre'] ?? '') === 'Monitor Multiparamétrico UCI H1');

// 4.2 Edición normal: cambio de nombre, unidad y reasignación de categoría (a Categoría 2)
$editProd = executeHttp('api', 'PUT', "/api/v1/productos/{$prod1Id}", [
    'sku' => 'BIO-MON-001',
    'nombre' => 'Monitor Multiparamétrico UCI 7 Canales Grado Médico',
    'categoria_id' => 2,
    'unidad' => 'SISTEMA'
], $sessionId);
assertTest("PUT /api/v1/productos/{id} actualiza registro (200 OK)", ($editProd['json']['success'] ?? false) === true);
assertTest("Nombre actualizado reflejado", ($editProd['json']['data']['nombre'] ?? '') === 'Monitor Multiparamétrico UCI 7 Canales Grado Médico');
assertTest("Categoría reasignada a #2", (int) ($editProd['json']['data']['categoria_id'] ?? 0) === 2);
assertTest("Unidad actualizada a SISTEMA", ($editProd['json']['data']['unidad'] ?? '') === 'SISTEMA');

// 4.3 Conflicto de SKU en edición: cambiar SKU de Producto 1 al SKU de Producto 2
$editConflictSku = executeHttp('api', 'PUT', "/api/v1/productos/{$prod1Id}", [
    'sku' => 'BIO-BOM-002', // SKU de Producto 2
    'nombre' => 'Intento Conflicto',
    'categoria_id' => 2,
    'unidad' => 'SISTEMA'
], $sessionId);
assertTest("PUT /api/v1/productos/{id} rechaza colisión de SKU (409)", ($editConflictSku['json']['success'] ?? true) === false);

// ============================================================
// FASE 5: INACTIVACIÓN LÓGICA (ZERO-PHYSICAL-DELETE)
// ============================================================
echo "\n--- 5. Inactivación Lógica (RN-012 Zero-Physical-Delete) ---\n";

// 5.1 DELETE lógico de Producto 2
$deleteProd2 = executeHttp('api', 'DELETE', "/api/v1/productos/{$prod2Id}", null, $sessionId);
assertTest("DELETE /api/v1/productos/{id} responde exitosamente (200 OK)", ($deleteProd2['json']['success'] ?? false) === true);
assertTest("Estado del producto retornado es 'inactivo'", ($deleteProd2['json']['data']['estado'] ?? '') === 'inactivo');

// 5.2 Verificación física directa en la BD MySQL
$stmtDb = $pdo->prepare("SELECT id, sku, nombre, categoria_id, estado FROM cat_producto WHERE id = :id");
$stmtDb->execute(['id' => $prod2Id]);
$dbRow = $stmtDb->fetch(PDO::FETCH_ASSOC);

assertTest("Verificación física BD: registro permanece en tabla cat_producto (no hubo DELETE FROM)", $dbRow !== false);
assertTest("Verificación física BD: columna 'estado' es estrictamente 'inactivo'", ($dbRow['estado'] ?? '') === 'inactivo');
assertTest("Verificación física BD: SKU BIO-BOM-002 preservado para auditoría", ($dbRow['sku'] ?? '') === 'BIO-BOM-002');

// 5.3 Idempotencia de inactivación
$deleteIdem = executeHttp('api', 'DELETE', "/api/v1/productos/{$prod2Id}", null, $sessionId);
assertTest("DELETE subsecuente sobre producto ya inactivo es idempotente (200 OK)", ($deleteIdem['json']['success'] ?? false) === true);
assertTest("Mantiene estado inactivo sin errores colaterales", ($deleteIdem['json']['data']['estado'] ?? '') === 'inactivo');

// ============================================================
// FASE 6: LISTADO FINAL CON ESTADOS Y RELACIONES
// ============================================================
echo "\n--- 6. Listado Final Integrado ---\n";

$listFinal = executeHttp('api', 'GET', '/api/v1/productos', null, $sessionId);
assertTest("GET /api/v1/productos post-modificaciones retorna 200 OK", ($listFinal['json']['success'] ?? false) === true);
$finalItems = $listFinal['json']['data'] ?? [];
assertTest("Listado contiene ambos productos gestionados", count($finalItems) === 2);

$foundProd1 = false;
$foundProd2 = false;
foreach ($finalItems as $item) {
    if ((int)$item['id'] === $prod1Id) {
        $foundProd1 = ($item['estado'] === 'activo' && (int)$item['categoria_id'] === 2);
    }
    if ((int)$item['id'] === $prod2Id) {
        $foundProd2 = ($item['estado'] === 'inactivo');
    }
}
assertTest("Producto 1 figura activo con categoría actualizada #2", $foundProd1);
assertTest("Producto 2 figura inactivo en el listado", $foundProd2);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
