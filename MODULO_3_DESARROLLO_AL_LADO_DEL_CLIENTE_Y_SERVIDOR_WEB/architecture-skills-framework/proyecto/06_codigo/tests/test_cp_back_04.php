<?php
declare(strict_types=1);

// Test automatizado para CP-BACK-04: POST /api/v1/auth/logout + GET /api/v1/auth/me

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-BACK-04: GET /auth/me + POST /auth/logout (S3IH)\n";
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
$runnerScript = __DIR__ . '/runner_auth_lifecycle.php';

// Asegurar usuario demo activo
$emailDemo = envRequired('DEMO_USER_EMAIL');
$passDemo = envRequired('DEMO_USER_PASSWORD');
$hashDemo = password_hash($passDemo, PASSWORD_ARGON2ID);

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE auth_usuario");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

$stmt = $pdo->prepare("
    INSERT INTO auth_usuario (id, nombre, email, hash_password, estado)
    VALUES (1, 'Supervisor Biomédica H1', :email, :hash, 'activo')
");
$stmt->execute(['email' => $emailDemo, 'hash' => $hashDemo]);

// Helper para ejecutar peticiones manteniendo o pasando el session ID
function execAuthRequest(string $method, string $uri, ?string $sessionId = null, ?array $payload = null): array
{
    global $phpBinary, $runnerScript;
    $payloadFile = __DIR__ . '/tmp_auth_payload.json';
    if ($payload !== null) {
        file_put_contents($payloadFile, json_encode($payload));
    } else {
        file_put_contents($payloadFile, '');
    }

    $code = sprintf(<<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;

$method = '%s';
$uri = '%s';
$sessId = '%s';
$payloadFile = '%s';

$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI'] = $uri;
$_SERVER['CONTENT_TYPE'] = 'application/json';

if ($sessId !== '') {
    $_COOKIE['s3ih_session'] = $sessId;
}

$raw = file_get_contents($payloadFile);
if ($raw !== '') {
    Request::$testInput = $raw;
}

// Iniciar router
$router = new Router();
foreach (require dirname(__DIR__) . '/routes/api.php' as [$m, $pattern, $handler]) {
    $router->add($m, $pattern, $handler);
}

// Registrar handler para capturar ID de sesión generado
register_shutdown_function(function() {
    $currentSessId = session_id();
    if ($currentSessId !== '') {
        file_put_contents(dirname(__DIR__) . '/tests/last_sess_id.txt', $currentSessId);
    }
});

$router->dispatch(new Request());
PHP
    , $method, $uri, $sessionId ?? '', addcslashes($payloadFile, "'\\"));

    file_put_contents($runnerScript, $code);
    $output = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
    @unlink($payloadFile);

    $capturedSession = null;
    $sessFile = __DIR__ . '/last_sess_id.txt';
    if (file_exists($sessFile)) {
        $capturedSession = trim(file_get_contents($sessFile));
        @unlink($sessFile);
    }

    $json = json_decode((string)$output, true);
    return ['output' => (string)$output, 'json' => $json, 'session_id' => $capturedSession];
}

// 1. CASO ANÓNIMO: GET /api/v1/auth/me sin sesión previa
echo "--- 1. Consulta Anónima (GET /auth/me sin autenticación) ---\n";
$resAnonimo = execAuthRequest('GET', '/api/v1/auth/me');
assertTest("Sin cookie retorna success: false", ($resAnonimo['json']['success'] ?? true) === false);
assertTest("Sin cookie retorna error 'No autenticado' (401)", ($resAnonimo['json']['error'] ?? '') === 'No autenticado');

// 2. CASO LOGIN EXITOSO: Obtener sesión
echo "\n--- 2. Autenticación Inicial (POST /auth/login) ---\n";
$resLogin = execAuthRequest('POST', '/api/v1/auth/login', null, [
    'email' => $emailDemo,
    'password' => $passDemo
]);
assertTest("Login responde exitosamente (200 OK)", ($resLogin['json']['success'] ?? false) === true);
$activeSessionId = $resLogin['session_id'];
assertTest("Identificador de sesión emitido y capturado", !empty($activeSessionId));

// 3. CASO AUTENTICADO: GET /api/v1/auth/me con sesión activa
echo "\n--- 3. Identidad de Usuario Activo (GET /auth/me con sesión) ---\n";
$resMe = execAuthRequest('GET', '/api/v1/auth/me', $activeSessionId);
assertTest("GET /auth/me responde JSON válido", is_array($resMe['json']));
assertTest("success es true en /auth/me autenticado", ($resMe['json']['success'] ?? false) === true);
assertTest("data.id coincide con el usuario autenticado", ($resMe['json']['data']['id'] ?? 0) === 1);
assertTest("data.nombre coincide con 'Supervisor Biomédica H1'", ($resMe['json']['data']['nombre'] ?? '') === 'Supervisor Biomédica H1');
assertTest("data.email coincide con el email institucional", ($resMe['json']['data']['email'] ?? '') === $emailDemo);
assertTest("data NO expone hash_password ni campos de auditoría", !isset($resMe['json']['data']['hash_password']));

// 4. CIERRE DE SESIÓN: POST /api/v1/auth/logout
echo "\n--- 4. Cierre de Sesión (POST /auth/logout) ---\n";
$resLogout = execAuthRequest('POST', '/api/v1/auth/logout', $activeSessionId);
assertTest("Logout responde exitosamente (200 OK)", ($resLogout['json']['success'] ?? false) === true);
assertTest("Mensaje de confirmación 'Sesión cerrada'", ($resLogout['json']['data']['mensaje'] ?? '') === 'Sesión cerrada');

// 5. POST-LOGOUT: GET /api/v1/auth/me con sesión revocada
echo "\n--- 5. Verificación Post-Logout (GET /auth/me con sesión destruida) ---\n";
$resPostLogout = execAuthRequest('GET', '/api/v1/auth/me', $activeSessionId);
assertTest("Acceso revocado tras logout (success: false)", ($resPostLogout['json']['success'] ?? true) === false);
assertTest("Retorna error 'No autenticado' (401)", ($resPostLogout['json']['error'] ?? '') === 'No autenticado');

// 6. IDEMPOTENCIA: POST /api/v1/auth/logout anónimo o repetido
echo "\n--- 6. Idempotencia de Logout ---\n";
$resLogoutIdempotente = execAuthRequest('POST', '/api/v1/auth/logout');
assertTest("Logout anónimo retorna success: true", ($resLogoutIdempotente['json']['success'] ?? false) === true);
assertTest("Mensaje 'Sesión cerrada' garantizado sin excepciones", ($resLogoutIdempotente['json']['data']['mensaje'] ?? '') === 'Sesión cerrada');

@unlink($runnerScript);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
