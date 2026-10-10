<?php
declare(strict_types=1);

// Test automatizado integral para CP-BACK-09: Suite Backend Integral (S3IH)
// Cobertura total: Health, Auth (Login/Logout/Me), Categorías (CRUD+Lógico), Productos (CRUD+Lógico), Seguridad & RN-012

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "SUITE BACKEND INTEGRAL (CP-BACK-09) - SISTEMA S3IH\n";
echo "Módulos: Auth + Categorías (Piloto) + Productos (Core)\n";
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
$runnerScript = __DIR__ . '/runner_suite_backend.php';

// Limpieza total y reinicio de fixtures
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE auth_usuario");
$pdo->exec("TRUNCATE TABLE cat_producto");
$pdo->exec("TRUNCATE TABLE cat_categoria");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// 1. Crear usuario demo oficial
$emailDemo = envRequired('DEMO_USER_EMAIL');
$passDemo = envRequired('DEMO_USER_PASSWORD');
$hashDemo = password_hash($passDemo, PASSWORD_ARGON2ID);
$stmtUser = $pdo->prepare("
    INSERT INTO auth_usuario (id, nombre, email, hash_password, estado)
    VALUES (1, 'Administrador Central S3IH', :email, :hash, 'activo')
");
$stmtUser->execute(['email' => $emailDemo, 'hash' => $hashDemo]);

// Función de invocación HTTP desacoplada
function callApi(string $method, string $uri, ?array $payload = null, ?string $sessionId = null): array
{
    global $phpBinary, $runnerScript;
    $payloadFile = __DIR__ . '/tmp_payload_suite.json';
    if ($payload !== null) {
        file_put_contents($payloadFile, json_encode($payload));
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

$payloadPath = '%s';
if ($payloadPath !== '' && file_exists($payloadPath)) {
    Request::$testInput = file_get_contents($payloadPath);
} else {
    Request::$testInput = null;
}

$router = new Router();
foreach (require dirname(__DIR__) . '/routes/api.php' as [$m, $pattern, $handler]) {
    $router->add($m, $pattern, $handler);
}

register_shutdown_function(function() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        file_put_contents(__DIR__ . '/active_suite_sess.txt', session_id());
    }
});

$router->dispatch(new Request());
PHP
    , $method, $uri, $sessionId ?? '', $payload !== null ? addcslashes($payloadFile, "'\\") : '');

    file_put_contents($runnerScript, $code);
    $output = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
    @unlink($payloadFile);
    $json = json_decode((string)$output, true);

    $capturedSession = null;
    $sessFile = __DIR__ . '/active_suite_sess.txt';
    if (file_exists($sessFile)) {
        $capturedSession = trim((string)file_get_contents($sessFile));
        @unlink($sessFile);
    }

    return ['output' => (string)$output, 'json' => $json, 'sessionId' => $capturedSession];
}

// ============================================================
// BLOQUE 1: INFRAESTRUCTURA Y HEALTH CHECK
// ============================================================
echo "--- 1. Infraestructura y Diagnóstico (/api/v1/health) ---\n";
$resHealth = callApi('GET', '/api/v1/health');
assertTest("GET /api/v1/health responde 200 OK con JSON válido", ($resHealth['json']['success'] ?? false) === true);
assertTest("Estado general del servicio hospitalario es 'ok'", ($resHealth['json']['data']['status'] ?? '') === 'ok');
assertTest("Conexión con base de datos MySQL 8.x es 'up'", ($resHealth['json']['data']['db'] ?? '') === 'up');

// ============================================================
// BLOQUE 2: SEGURIDAD, AUTENTICACIÓN Y SESIONES (AUTH)
// ============================================================
echo "\n--- 2. Autenticación y Control de Acceso (Auth) ---\n";

// 2.1 Intentos fallidos (CWE-204 timing/mensaje seguro)
$resLoginFail = callApi('POST', '/api/v1/auth/login', ['email' => 'hacker@redhospitalaria.local', 'password' => 'clave_invalida']);
assertTest("Login con credenciales incorrectas retorna error 401", ($resLoginFail['json']['success'] ?? true) === false);
assertTest("Mensaje de error genérico anti-enumeración (CWE-204)", ($resLoginFail['json']['error'] ?? '') === 'Credenciales inválidas');

// 2.2 Login exitoso
$resLoginOk = callApi('POST', '/api/v1/auth/login', ['email' => $emailDemo, 'password' => $passDemo]);
assertTest("Login con credenciales correctas retorna 200 OK", ($resLoginOk['json']['success'] ?? false) === true);
assertTest("Usuario retornado en data.email coincide", ($resLoginOk['json']['data']['email'] ?? '') === $emailDemo);
$sessionId = $resLoginOk['sessionId'];
assertTest("Sesión PHP 's3ih_session' generada exitosamente", !empty($sessionId));

// 2.3 Endpoint de introspección /me
$resMe = callApi('GET', '/api/v1/auth/me', null, $sessionId);
assertTest("GET /api/v1/auth/me retorna datos de sesión activa", ($resMe['json']['success'] ?? false) === true);
assertTest("data.nombre verificado en sesión", ($resMe['json']['data']['nombre'] ?? '') === 'Administrador Central S3IH');

// 2.4 Control de acceso sin sesión
$resNoAuth = callApi('GET', '/api/v1/productos', null, null);
assertTest("Acceso a ruta protegida sin sesión retorna 401 Unauthorized", ($resNoAuth['json']['error'] ?? '') === 'No autenticado');

// ============================================================
// BLOQUE 3: MÓDULO PILOTO DE CATEGORÍAS (CRUD + ZERO-DELETE)
// ============================================================
echo "\n--- 3. Módulo de Categorías Hospitalarias (/api/v1/categorias) ---\n";

// 3.1 Crear categoría raíz
$payloadCat1 = ['nombre' => 'Equipos Biomédicos y Soporte Vital'];
$resCat1 = callApi('POST', '/api/v1/categorias', $payloadCat1, $sessionId);
assertTest("POST /categorias crea categoría raíz (201 Created)", ($resCat1['json']['success'] ?? false) === true);
$catId1 = (int)($resCat1['json']['data']['id'] ?? 0);
assertTest("ID de categoría 1 generado", $catId1 > 0);

// 3.2 Crear subcategoría hija
$payloadCat2 = ['nombre' => 'Monitores Cardíacos y UCI', 'categoria_padre_id' => $catId1];
$resCat2 = callApi('POST', '/api/v1/categorias', $payloadCat2, $sessionId);
assertTest("POST /categorias crea subcategoría con FK válida", ($resCat2['json']['success'] ?? false) === true);
$catId2 = (int)($resCat2['json']['data']['id'] ?? 0);
assertTest("Subcategoría referencia padre correctamente", ($resCat2['json']['data']['categoria_padre_id'] ?? 0) === $catId1);

// 3.3 Regla de negocio: Anti-ciclo en actualización
$payloadCiclo = ['nombre' => 'Intento Ciclo', 'categoria_padre_id' => $catId1];
$resCiclo = callApi('PUT', "/api/v1/categorias/{$catId1}", $payloadCiclo, $sessionId);
assertTest("Categoría padre de sí misma retorna error 400 (auto-padre)", str_contains($resCiclo['json']['error'] ?? '', 'auto-padre'));

// 3.4 Inactivación lógica de subcategoría
$resDelCat = callApi('DELETE', "/api/v1/categorias/{$catId2}", null, $sessionId);
assertTest("DELETE /categorias/{id} ejecuta inactivación lógica (200 OK)", ($resDelCat['json']['success'] ?? false) === true);
assertTest("Estado de categoría cambió a 'inactivo'", ($resDelCat['json']['data']['estado'] ?? '') === 'inactivo');

// Verificación en BD Zero-Delete de categoría
$stmtCatDb = $pdo->prepare("SELECT COUNT(*) as total, estado FROM cat_categoria WHERE id = :id GROUP BY estado");
$stmtCatDb->execute(['id' => $catId2]);
$rowCatDb = $stmtCatDb->fetch();
assertTest("Categoría persiste en BD (COUNT=1, RN-012 Zero-Physical-Delete)", ($rowCatDb['total'] ?? 0) == 1);

// ============================================================
// BLOQUE 4: SEGUNDO MÓDULO - PRODUCTOS HOSPITALARIOS
// ============================================================
echo "\n--- 4. Módulo de Productos Hospitalarios (/api/v1/productos) ---\n";

// 4.1 Crear producto con FK válida
$payloadProd1 = [
    'sku' => 'MED-DESF-001',
    'nombre' => 'Desfibrilador Hospitalario con Palas Pediátricas',
    'categoria_id' => $catId1,
    'unidad' => 'EQUIPO'
];
$resProd1 = callApi('POST', '/api/v1/productos', $payloadProd1, $sessionId);
assertTest("POST /productos crea producto con FK válida (201 Created)", ($resProd1['json']['success'] ?? false) === true);
$prodId1 = (int)($resProd1['json']['data']['id'] ?? 0);
assertTest("ID de producto generado", $prodId1 > 0);
assertTest("SKU retornado coincide ('MED-DESF-001')", ($resProd1['json']['data']['sku'] ?? '') === 'MED-DESF-001');

// 4.2 Validar FK inexistente
$payloadProdBadFk = [
    'sku' => 'MED-BAD-999',
    'nombre' => 'Producto con FK Huérfana',
    'categoria_id' => 999999,
    'unidad' => 'UNIDAD'
];
$resProdBadFk = callApi('POST', '/api/v1/productos', $payloadProdBadFk, $sessionId);
assertTest("POST con categoria_id inexistente retorna error 404", str_contains($resProdBadFk['json']['error'] ?? '', 'categoria_id no existe'));

// 4.3 Control de unicidad de SKU (409 Conflict)
$payloadProdDupSku = [
    'sku' => 'MED-DESF-001',
    'nombre' => 'Segundo Desfibrilador Intento Duplicar',
    'categoria_id' => $catId1,
    'unidad' => 'UNIDAD'
];
$resProdDupSku = callApi('POST', '/api/v1/productos', $payloadProdDupSku, $sessionId);
assertTest("POST con SKU repetido retorna HTTP 409 Conflict", str_contains($resProdDupSku['json']['error'] ?? '', 'sku ya existe'));

// 4.4 Actualización (PUT /productos/{id})
$payloadProdUpdate = [
    'sku' => 'MED-DESF-001',
    'nombre' => 'Desfibrilador Hospitalario UCI Reacondicionado',
    'categoria_id' => $catId1,
    'unidad' => 'EQUIPO'
];
$resProdUpdate = callApi('PUT', "/api/v1/productos/{$prodId1}", $payloadProdUpdate, $sessionId);
assertTest("PUT /productos/{id} actualiza registro conservando su SKU (200 OK)", ($resProdUpdate['json']['success'] ?? false) === true);
assertTest("Nombre actualizado reflejado", ($resProdUpdate['json']['data']['nombre'] ?? '') === 'Desfibrilador Hospitalario UCI Reacondicionado');

// 4.5 Listado y detalle individual
$resProdList = callApi('GET', '/api/v1/productos', null, $sessionId);
assertTest("GET /productos lista elementos correctamente", count($resProdList['json']['data'] ?? []) >= 1);

$resProdShow = callApi('GET', "/api/v1/productos/{$prodId1}", null, $sessionId);
assertTest("GET /productos/{id} retorna detalle individual", ($resProdShow['json']['data']['id'] ?? 0) === $prodId1);

// 4.6 Inactivación lógica de producto (DELETE /productos/{id})
$resProdDel = callApi('DELETE', "/api/v1/productos/{$prodId1}", null, $sessionId);
assertTest("DELETE /productos/{id} ejecuta inactivación lógica (200 OK)", ($resProdDel['json']['success'] ?? false) === true);
assertTest("Estado de producto cambió a 'inactivo'", ($resProdDel['json']['data']['estado'] ?? '') === 'inactivo');

// 4.7 Idempotencia de borrado lógico
$resProdDelIdem = callApi('DELETE', "/api/v1/productos/{$prodId1}", null, $sessionId);
assertTest("Segundo DELETE /productos/{id} es idempotente (200 OK)", ($resProdDelIdem['json']['success'] ?? false) === true);

// 4.8 Verificación de persistencia e inmutabilidad en BD (RN-012)
$stmtProdDb = $pdo->prepare("SELECT COUNT(*) as total, estado FROM cat_producto WHERE id = :id GROUP BY estado");
$stmtProdDb->execute(['id' => $prodId1]);
$rowProdDb = $stmtProdDb->fetch();
assertTest("Producto persiste en BD (COUNT=1, Cero sentencias destructivas)", ($rowProdDb['total'] ?? 0) == 1);
assertTest("Estado en BD es estrictamente 'inactivo'", ($rowProdDb['estado'] ?? '') === 'inactivo');

// ============================================================
// BLOQUE 5: CIERRE DE SESIÓN Y GOBERNANZA FINAL
// ============================================================
echo "\n--- 5. Cierre de Sesión y Validación de Seguridad ---\n";
$resLogout = callApi('POST', '/api/v1/auth/logout', null, $sessionId);
assertTest("POST /api/v1/auth/logout destruye sesión exitosamente", ($resLogout['json']['success'] ?? false) === true);

$resMeAfterLogout = callApi('GET', '/api/v1/auth/me', null, $sessionId);
assertTest("GET /me tras logout retorna 401 No autenticado", ($resMeAfterLogout['json']['error'] ?? '') === 'No autenticado');

// Auditoría RN-012 sobre ambos repositorios
$catRepoCode = file_get_contents(dirname(__DIR__) . '/app/Repositories/CategoriaRepository.php');
$prodRepoCode = file_get_contents(dirname(__DIR__) . '/app/Repositories/ProductoRepository.php');
$allReposCode = preg_replace('#//.*$|/\*.*?\*/#m', '', (string)$catRepoCode . (string)$prodRepoCode);
assertTest("Auditoría RN-012: Cero cláusulas 'DELETE FROM' en todos los repositorios", !stripos((string)$allReposCode, 'DELETE FROM'));

@unlink($runnerScript);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
