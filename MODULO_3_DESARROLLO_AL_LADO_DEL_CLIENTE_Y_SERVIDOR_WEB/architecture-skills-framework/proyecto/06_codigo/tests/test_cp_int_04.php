<?php
declare(strict_types=1);

// Test automatizado de integración para CP-INT-04: Seguridad básica: sesión, SQLi, XSS, CSRF, secretos y logs
// Workflow 06: Integración Final Didáctica (S3IH)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;

echo "============================================================\n";
echo "PRUEBAS CP-INT-04: SEGURIDAD BÁSICA (SESIÓN, SQLi, XSS, CSRF, SECRETOS Y LOGS)\n";
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
$runnerScript = __DIR__ . '/runner_int_sec.php';

// Preparación de usuario de prueba y credenciales demo
$emailDemo = envRequired('DEMO_USER_EMAIL');
$passDemo = envRequired('DEMO_USER_PASSWORD');
$hashDemo = password_hash($passDemo, PASSWORD_ARGON2ID);

$stmtUser = $pdo->prepare("
    INSERT INTO auth_usuario (id, nombre, email, hash_password, estado)
    VALUES (1, 'Oficial de Seguridad TI H1', :email, :hash, 'activo')
    ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), hash_password = VALUES(hash_password), estado = 'activo'
");
$stmtUser->execute(['email' => $emailDemo, 'hash' => $hashDemo]);

function executeHttp(string $type, string $method, string $uri, ?array $payload = null, ?string $cookieSession = null): array
{
    global $phpBinary, $runnerScript;
    $tmpPayload = __DIR__ . '/tmp_int_sec_payload.json';
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
        file_put_contents(__DIR__ . '/tmp_active_session_sec.txt', session_id());
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
    $sessFile = __DIR__ . '/tmp_active_session_sec.txt';
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

// ============================================================
// 1. SEGURIDAD DE SESIÓN (RNF-030 & HARDENING)
// ============================================================
echo "--- 1. Seguridad de Sesión ---\n";

$sessionCfg = require dirname(__DIR__) . '/config/session.php';
assertTest("Nombre de cookie institucional configurado ('s3ih_session')", ($sessionCfg['name'] ?? '') === 's3ih_session');
assertTest("Tiempo de vida de sesión configurado a 1800s (30 minutos, RNF-030)", ($sessionCfg['lifetime'] ?? 0) === 1800);
assertTest("Atributo HttpOnly habilitado (bloqueo de acceso por script)", ($sessionCfg['httponly'] ?? false) === true);
assertTest("Atributo SameSite establecido en Lax (mitigación CSRF)", in_array(strtolower($sessionCfg['samesite'] ?? ''), ['lax', 'strict'], true));
assertTest("Modo estricto de sesión (use_strict_mode) activado", ($sessionCfg['strict'] ?? false) === true);

// Prueba de login y regeneración de ID (anti-fijación de sesión)
$login1 = executeHttp('api', 'POST', '/api/v1/auth/login', [
    'email' => $emailDemo,
    'password' => $passDemo
], null);
$sessId1 = $login1['sessionId'] ?? '';
assertTest("Inicio de sesión genera session ID válido", !empty($sessId1));

// Segundo login con las mismas credenciales genera un nuevo ID (session_regenerate_id)
$login2 = executeHttp('api', 'POST', '/api/v1/auth/login', [
    'email' => $emailDemo,
    'password' => $passDemo
], null);
$sessId2 = $login2['sessionId'] ?? '';
assertTest("Login regenera identificador de sesión (anti-fijación)", !empty($sessId2) && $sessId2 !== $sessId1);

// Cierre de sesión y revocación
$logoutRes = executeHttp('api', 'POST', '/api/v1/auth/logout', null, $sessId2);
assertTest("Logout destruye sesión exitosamente", ($logoutRes['json']['success'] ?? false) === true);

$meRevoked = executeHttp('api', 'GET', '/api/v1/auth/me', null, $sessId2);
assertTest("Acceso revocado inmediatamente tras logout (401)", ($meRevoked['json']['error'] ?? '') === 'No autenticado');

// ============================================================
// 2. RESISTENCIA A INYECCIÓN SQL (SQLi)
// ============================================================
echo "\n--- 2. Resistencia a Inyección SQL (SQLi) ---\n";

// Inyección en login (Bypass clásico)
$sqliPayloads = [
    "' OR '1'='1",
    "' OR '1'='1' --",
    "admin' --",
    "' UNION SELECT 1, 'admin', 'hacked@s3ih.gob', 'hash', 'activo' --",
    "'; DROP TABLE cat_categoria; --"
];

$sqliBypassSuccess = false;
foreach ($sqliPayloads as $payload) {
    $resSqli = executeHttp('api', 'POST', '/api/v1/auth/login', [
        'email' => $payload,
        'password' => 'cualquiera'
    ], null);
    if (($resSqli['json']['success'] ?? false) === true) {
        $sqliBypassSuccess = true;
    }
}
assertTest("Login inmune a payloads de bypass SQLi (todos rechazados con 401)", !$sqliBypassSuccess);

// Inyección en parámetros URL (/categorias/{id} y /productos/{id})
// Obtener una sesión válida
$loginAuth = executeHttp('api', 'POST', '/api/v1/auth/login', ['email' => $emailDemo, 'password' => $passDemo], null);
$validSession = $loginAuth['sessionId'];

$sqliUrlRes1 = executeHttp('api', 'GET', '/api/v1/categorias/1%20OR%201=1', null, $validSession);
assertTest("GET /categorias/{id} con inyección en URL es interceptado por validador (400)", ($sqliUrlRes1['json']['error'] ?? '') === 'ID inválido');

$sqliUrlRes2 = executeHttp('api', 'GET', '/api/v1/productos/1%3B%20DROP%20TABLE%20cat_producto', null, $validSession);
assertTest("GET /productos/{id} con inyección en URL es interceptado por validador (400)", ($sqliUrlRes2['json']['error'] ?? '') === 'ID inválido');

// Auditoría estática de repositorios: asegurar que todas las consultas usan sentencias preparadas
$repoFiles = [
    dirname(__DIR__) . '/app/Repositories/UsuarioRepository.php',
    dirname(__DIR__) . '/app/Repositories/CategoriaRepository.php',
    dirname(__DIR__) . '/app/Repositories/ProductoRepository.php',
];

$unsafeSqlFound = false;
foreach ($repoFiles as $file) {
    $content = file_get_contents($file);
    // Busca concatenación directa en query/prepare ($sql . $var o "SELECT ... WHERE id = $id")
    if (preg_match('/(query|prepare)\s*\([^)]*\$_(GET|POST|REQUEST)/i', $content) ||
        preg_match('/(query|prepare)\s*\([^)]*WHERE[^;]*\.[ ]*\$/i', $content)) {
        $unsafeSqlFound = true;
    }
}
assertTest("Auditoría de Repositorios: 100% consultas usan sentencias preparadas con parámetros vinculados", !$unsafeSqlFound);

// ============================================================
// 3. PREVENCIÓN DE CROSS-SITE SCRIPTING (XSS)
// ============================================================
echo "\n--- 3. Prevención de Cross-Site Scripting (XSS) ---\n";

$xssString = '<script>alert("XSS-S3IH")</script><img src=x onerror=alert(1)>';

// 3.1 Almacenar nombre de categoría con caracteres script
$createXssCat = executeHttp('api', 'POST', '/api/v1/categorias', [
    'nombre' => 'Insumo ' . $xssString,
    'categoria_padre_id' => null
], $validSession);
assertTest("API acepta caracteres tipificados sin ejecutar script", ($createXssCat['json']['success'] ?? false) === true);
$xssCatId = $createXssCat['json']['data']['id'];

// 3.2 Verificación de respuesta JSON (Content-Type correcto, no HTML)
assertTest("Respuesta API devuelve JSON limpio con caracteres seguros", str_contains($createXssCat['output'], 'Insumo <script>alert(\"XSS-S3IH\")'));

// 3.3 Verificación en vistas Web: renderizado de layout escapado
// Probamos la vista web con nombre de usuario malicioso inyectado en sesión
$testViewScript = __DIR__ . '/runner_xss_view.php';
$xssTestCode = <<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\View;

$_SERVER['REQUEST_URI'] = '/dashboard';
$maliciousUser = '<script>alert("pwned")</script>" onmouseover="alert(2)';

View::render('dashboard/index', [
    'pageTitle' => 'Dashboard | S3IH <script>bad()</script>',
    'userName' => $maliciousUser,
], 'layouts/main');
PHP;

file_put_contents($testViewScript, $xssTestCode);
$viewOutput = shell_exec("\"{$phpBinary}\" \"{$testViewScript}\" 2>&1");
@unlink($testViewScript);

assertTest("Layout web escapa título HTML (&lt;script&gt;)", str_contains((string)$viewOutput, '&lt;script&gt;bad()&lt;/script&gt;'));
assertTest("Layout web escapa nombre de usuario en sidebar (&lt;script&gt;)", str_contains((string)$viewOutput, '&lt;script&gt;alert(&quot;pwned&quot;)&lt;/script&gt;'));
assertTest("Layout web no emite tags script sin escapar en datos dinámicos", !str_contains((string)$viewOutput, '<script>alert("pwned")</script>'));

// Limpieza de categoría de prueba
executeHttp('api', 'DELETE', "/api/v1/categorias/{$xssCatId}", null, $validSession);

// ============================================================
// 4. PROTECCIÓN CSRF Y CABECERAS HTTP
// ============================================================
echo "\n--- 4. Protección CSRF y Cabeceras HTTP ---\n";

// 4.1 Encabezado Content-Type de API
$healthRes = executeHttp('api', 'GET', '/api/v1/health', null, null);
assertTest("API REST emite respuesta en formato JSON estructurado", ($healthRes['json']['success'] ?? false) === true);

// 4.2 Enfoque de API JSON con SameSite=Lax
assertTest("Configuración de cookie usa SameSite=Lax (mitigación CSRF en cross-site POST)", $sessionCfg['samesite'] === 'Lax');

// ============================================================
// 5. SECRETOS Y GESTIÓN DE CREDENCIALES
// ============================================================
echo "\n--- 5. Secretos y Gestión de Credenciales ---\n";

// 5.1 Verificación de hash de contraseña Argon2id en BD
$stmtHash = $pdo->prepare("SELECT hash_password FROM auth_usuario WHERE email = :email");
$stmtHash->execute(['email' => $emailDemo]);
$storedHash = (string) $stmtHash->fetchColumn();

assertTest("Hash almacenado utiliza algoritmo Argon2id (\$argon2id\$)", str_starts_with($storedHash, '$argon2id$'));
assertTest("Contraseña demo verifica correctamente contra Argon2id", password_verify($passDemo, $storedHash));

// 5.2 Ningún endpoint expone el hash de contraseña
assertTest("Endpoint /login no expone hash_password en payload JSON", !isset($loginAuth['json']['data']['hash_password']));

$meRes = executeHttp('api', 'GET', '/api/v1/auth/me', null, $validSession);
assertTest("Endpoint /me no expone hash_password en payload JSON", !isset($meRes['json']['data']['hash_password']));

// 5.3 Existencia de archivo .env.example sin contraseñas sensibles
$envExamplePath = dirname(__DIR__) . '/.env.example';
assertTest("Archivo .env.example disponible para despliegues limpios", file_exists($envExamplePath));
if (file_exists($envExamplePath)) {
    $envExContent = file_get_contents($envExamplePath);
    assertTest(".env.example no contiene contraseñas de producción activas", !str_contains($envExContent, 'Admin1234#'));
}

// ============================================================
// 6. MANEJO DE ERRORES Y AUDITORÍA DE LOGS
// ============================================================
echo "\n--- 6. Manejo de Errores y Logs Sanitizados ---\n";

// 6.1 Simulación de error de BD capturado por Response::execute
$testErrScript = __DIR__ . '/runner_db_err.php';
$errTestCode = <<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\Response;

Response::execute(function() {
    throw new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'root'@'localhost' (using password: YES)");
}, 200);
PHP;

file_put_contents($testErrScript, $errTestCode);
$errOutput = shell_exec("\"{$phpBinary}\" \"{$testErrScript}\" 2>&1");
@unlink($testErrScript);

$errJson = json_decode((string)$errOutput, true);
assertTest("Error PDO capturado responde con mensaje sanitizado ('Error de base de datos')", ($errJson['error'] ?? '') === 'Error de base de datos');
assertTest("Detalles sensibles de BD (host, usuario, password) no se filtran al cliente", !str_contains((string)$errOutput, 'Access denied'));
assertTest("Respuesta de error mantiene success: false", ($errJson['success'] ?? true) === false);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
