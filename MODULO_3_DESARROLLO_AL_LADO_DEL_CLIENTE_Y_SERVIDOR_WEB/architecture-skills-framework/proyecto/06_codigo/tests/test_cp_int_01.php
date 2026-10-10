<?php
declare(strict_types=1);

// Test automatizado de integración para CP-INT-01: Login → Dashboard → Logout (S3IH)
// Valida el ciclo de vida de autenticación completo punta a punta entre capa Web, capa API, sesiones PHP y base de datos.

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-INT-01: LOGIN → DASHBOARD → LOGOUT (S3IH)\n";
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
$runnerScript = __DIR__ . '/runner_int_auth.php';

// Limpieza total y preparación de usuario
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE auth_usuario");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

$emailDemo = envRequired('DEMO_USER_EMAIL');
$passDemo = envRequired('DEMO_USER_PASSWORD');
$hashDemo = password_hash($passDemo, PASSWORD_ARGON2ID);
$stmtUser = $pdo->prepare("
    INSERT INTO auth_usuario (id, nombre, email, hash_password, estado)
    VALUES (1, 'Dra. Elena Ramos - Guardia UCI H1', :email, :hash, 'activo')
");
$stmtUser->execute(['email' => $emailDemo, 'hash' => $hashDemo]);

function executeHttp(string $type, string $method, string $uri, ?array $payload = null, ?string $cookieSession = null): array
{
    global $phpBinary, $runnerScript;
    $tmpPayload = __DIR__ . '/tmp_int_payload.json';
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
        file_put_contents(__DIR__ . '/tmp_active_session_id.txt', session_id());
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
    $sessFile = __DIR__ . '/tmp_active_session_id.txt';
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
// FASE 1: ESTADO INICIAL ANÓNIMO (SIN SESIÓN)
// ============================================================
echo "--- 1. Estado Inicial Anónimo (Acceso Protegido Bloqueado) ---\n";

// 1.1 Web: Acceso a raíz / sin sesión redirige
$resWebRootAnon = executeHttp('web', 'GET', '/', null, null);
assertTest("GET / sin sesión redirige a login (no emite dashboard)", !str_contains($resWebRootAnon['output'], 'main-content'));

// 1.2 Web: Acceso a /dashboard sin sesión redirige
$resWebDashAnon = executeHttp('web', 'GET', '/dashboard', null, null);
assertTest("GET /dashboard sin sesión redirige (no emite dashboard)", !str_contains($resWebDashAnon['output'], 'main-content'));

// 1.3 Web: Pantalla /login carga formulario accesible
$resWebLogin = executeHttp('web', 'GET', '/login', null, null);
assertTest("GET /login sin sesión renderiza formulario", str_contains($resWebLogin['output'], 'id="login-form"'));

// 1.4 API: Endpoint /me sin sesión retorna 401 Unauthorized
$resApiMeAnon = executeHttp('api', 'GET', '/api/v1/auth/me', null, null);
assertTest("GET /api/v1/auth/me sin sesión retorna error 401", ($resApiMeAnon['json']['error'] ?? '') === 'No autenticado');
assertTest("API success es false sin sesión", ($resApiMeAnon['json']['success'] ?? true) === false);

// ============================================================
// FASE 2: PROCESO DE LOGIN Y CREACIÓN DE SESIÓN
// ============================================================
echo "\n--- 2. Autenticación Exitosa (Login e2e) ---\n";

// 2.1 Intento de login fallido
$resLoginBad = executeHttp('api', 'POST', '/api/v1/auth/login', [
    'email' => $emailDemo,
    'password' => 'password_erroneo_123'
], null);
assertTest("Login con clave incorrecta retorna 401", ($resLoginBad['json']['error'] ?? '') === 'Credenciales inválidas');

// 2.2 Login con credenciales válidas
$resLoginOk = executeHttp('api', 'POST', '/api/v1/auth/login', [
    'email' => $emailDemo,
    'password' => $passDemo
], null);
assertTest("Login con credenciales válidas responde 200 OK", ($resLoginOk['json']['success'] ?? false) === true);
assertTest("Datos de usuario retornan email correcto", ($resLoginOk['json']['data']['email'] ?? '') === $emailDemo);
assertTest("Datos de usuario retornan nombre correcto", ($resLoginOk['json']['data']['nombre'] ?? '') === 'Dra. Elena Ramos - Guardia UCI H1');

$activeSessionId = $resLoginOk['sessionId'];
assertTest("Cookie de sesión PHP 's3ih_session' capturada con éxito", !empty($activeSessionId));

// ============================================================
// FASE 3: ESTADO AUTENTICADO (DASHBOARD Y SESIÓN ACTIVA)
// ============================================================
echo "\n--- 3. Navegación Autenticada (Dashboard y API Introspección) ---\n";

// 3.1 Web: Redirección automática desde / hacia /dashboard con sesión
$resWebRootAuth = executeHttp('web', 'GET', '/', null, $activeSessionId);
assertTest("GET / con sesión activa redirige a dashboard (no emite login)", !str_contains($resWebRootAuth['output'], 'auth-card'));

// 3.2 Web: Renderizado de /dashboard autenticado
$resWebDashAuth = executeHttp('web', 'GET', '/dashboard', null, $activeSessionId);
$dashHtml = $resWebDashAuth['output'];
assertTest("GET /dashboard renderiza layout autenticado (<body class=\"page-main\">)", str_contains($dashHtml, '<body class="page-main">'));
assertTest("Dashboard renderiza bienvenida a Dra. Elena Ramos", str_contains($dashHtml, 'Dra. Elena Ramos - Guardia UCI H1'));
assertTest("Dashboard contiene sidebar con branding S3IH Hospitalario", str_contains($dashHtml, 'S3IH Hospitalario'));
assertTest("Dashboard incluye botón de salida (#logout-btn)", str_contains($dashHtml, 'id="logout-btn"'));

// 3.3 API: Endpoint /me retorna identidad de la sesión
$resApiMeAuth = executeHttp('api', 'GET', '/api/v1/auth/me', null, $activeSessionId);
assertTest("GET /api/v1/auth/me con sesión activa responde 200 OK", ($resApiMeAuth['json']['success'] ?? false) === true);
assertTest("Identidad en /me coincide con la de sesión", ($resApiMeAuth['json']['data']['nombre'] ?? '') === 'Dra. Elena Ramos - Guardia UCI H1');

// ============================================================
// FASE 4: CIERRE DE SESIÓN (LOGOUT E2E)
// ============================================================
echo "\n--- 4. Cierre de Sesión (Logout e2e) ---\n";

$resLogout = executeHttp('api', 'POST', '/api/v1/auth/logout', null, $activeSessionId);
assertTest("POST /api/v1/auth/logout responde 200 OK", ($resLogout['json']['success'] ?? false) === true);
assertTest("Mensaje confirma 'Sesión cerrada'", ($resLogout['json']['data']['mensaje'] ?? '') === 'Sesión cerrada');

// ============================================================
// FASE 5: VERIFICACIÓN POST-LOGOUT (ACCESO REVOCADO)
// ============================================================
echo "\n--- 5. Verificación Post-Logout (Acceso Revocado en Toda la App) ---\n";

// 5.1 API /me con la sesión revocada debe rechazar
$resMePostLogout = executeHttp('api', 'GET', '/api/v1/auth/me', null, $activeSessionId);
assertTest("GET /api/v1/auth/me con sesión destruida retorna 401 No autenticado", ($resMePostLogout['json']['error'] ?? '') === 'No autenticado');

// 5.2 Web /dashboard con la sesión revocada debe denegar y redirigir
$resDashPostLogout = executeHttp('web', 'GET', '/dashboard', null, $activeSessionId);
assertTest("GET /dashboard tras logout redirige a login (no renderiza contenido)", !str_contains($resDashPostLogout['output'], 'main-content'));

// 5.3 Idempotencia del endpoint logout
$resLogoutIdem = executeHttp('api', 'POST', '/api/v1/auth/logout', null, $activeSessionId);
assertTest("POST /api/v1/auth/logout subsecuente responde 200 OK (idempotente)", ($resLogoutIdem['json']['success'] ?? false) === true);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
