<?php
declare(strict_types=1);

// Test automatizado para CP-BACK-03: POST /api/v1/auth/login con sesión PHP

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-BACK-03: POST /api/v1/auth/login (SESIÓN PHP)\n";
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
$runnerScript = __DIR__ . '/runner_auth_login.php';

// Asegurar usuario demo activo y usuario inactivo para pruebas
$emailDemo = envRequired('DEMO_USER_EMAIL');
$passDemo = envRequired('DEMO_USER_PASSWORD');
$hashDemo = password_hash($passDemo, PASSWORD_ARGON2ID);

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE auth_usuario");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

$stmt = $pdo->prepare("
    INSERT INTO auth_usuario (id, nombre, email, hash_password, estado)
    VALUES (:id, :nombre, :email, :hash, :estado)
");
$stmt->execute(['id' => 1, 'nombre' => 'Supervisor Biomédica H1', 'email' => $emailDemo, 'hash' => $hashDemo, 'estado' => 'activo']);
$stmt->execute(['id' => 2, 'nombre' => 'Usuario Suspendido', 'email' => 'inactivo.h1@s3ih.salud.gob', 'hash' => $hashDemo, 'estado' => 'inactivo']);

function postLogin(string $payloadJson): array
{
    global $phpBinary, $runnerScript;
    $payloadFile = __DIR__ . '/tmp_login_payload.json';
    file_put_contents($payloadFile, $payloadJson);

    $code = sprintf(<<<'PHP'
<?php
require dirname(__DIR__) . '/app/Core/bootstrap.php';
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/api/v1/auth/login';
$_SERVER['CONTENT_TYPE'] = 'application/json';

Request::$testInput = file_get_contents('%s');

$router = new Router();
foreach (require dirname(__DIR__) . '/routes/api.php' as [$method, $pattern, $handler]) {
    $router->add($method, $pattern, $handler);
}

$router->dispatch(new Request());
PHP
    , addcslashes($payloadFile, "'\\"));

    file_put_contents($runnerScript, $code);
    $output = shell_exec("\"{$phpBinary}\" \"{$runnerScript}\" 2>&1");
    @unlink($payloadFile);
    $json = json_decode((string)$output, true);
    return ['output' => (string)$output, 'json' => $json];
}

// 1. CASO LOGIN EXITOSO (HTTP 200 OK)
echo "--- 1. Login Exitoso con Credenciales Válidas (HTTP 200 OK) ---\n";
$resOk = postLogin(json_encode([
    'email' => $emailDemo,
    'password' => $passDemo
]));

assertTest("Login exitoso responde JSON válido", is_array($resOk['json']));
assertTest("success es true en login exitoso", ($resOk['json']['success'] ?? false) === true);
assertTest("data contiene id del usuario", ($resOk['json']['data']['id'] ?? 0) === 1);
assertTest("data contiene nombre del usuario", str_contains($resOk['json']['data']['nombre'] ?? '', 'Supervisor Biomédica'));
assertTest("data contiene email institucional", ($resOk['json']['data']['email'] ?? '') === $emailDemo);
assertTest("data NO expone hash_password ni estado interno", !isset($resOk['json']['data']['hash_password']) && !isset($resOk['json']['data']['estado']));

// 2. CASOS DE CREDENCIALES INVÁLIDAS (HTTP 401 Unauthorized - Anti-Enumeración)
echo "\n--- 2. Credenciales Inválidas (HTTP 401 Unauthorized) ---\n";

// Caso 2.1: Contraseña equivocada
$resClaveMal = postLogin(json_encode([
    'email' => $emailDemo,
    'password' => 'ClaveIncorrecta#2026'
]));
assertTest("Contraseña incorrecta retorna success: false", ($resClaveMal['json']['success'] ?? true) === false);
assertTest("Contraseña incorrecta retorna 'Credenciales inválidas' (401)", ($resClaveMal['json']['error'] ?? '') === 'Credenciales inválidas');

// Caso 2.2: Email inexistente
$resEmailNoExiste = postLogin(json_encode([
    'email' => 'no_existe_h1@s3ih.salud.gob',
    'password' => $passDemo
]));
assertTest("Email inexistente retorna 'Credenciales inválidas' (sin enumerar usuario)", ($resEmailNoExiste['json']['error'] ?? '') === 'Credenciales inválidas');

// Caso 2.3: Usuario inactivo / suspendido
$resInactivo = postLogin(json_encode([
    'email' => 'inactivo.h1@s3ih.salud.gob',
    'password' => $passDemo
]));
assertTest("Usuario suspendido retorna 'Credenciales inválidas' (401)", ($resInactivo['json']['error'] ?? '') === 'Credenciales inválidas');

// 3. VALIDACIÓN DE ENTRADA (HTTP 400 Bad Request)
echo "\n--- 3. Validación de Payload (HTTP 400 Bad Request) ---\n";

// Caso 3.1: Formato de email inválido
$resEmailInvalido = postLogin(json_encode([
    'email' => 'no-es-un-correo-valido',
    'password' => $passDemo
]));
assertTest("Email con formato inválido retorna error 400", str_contains($resEmailInvalido['json']['error'] ?? '', 'email: obligatorio'));

// Caso 3.2: Password vacío
$resPasswordVacio = postLogin(json_encode([
    'email' => $emailDemo,
    'password' => ''
]));
assertTest("Password vacío retorna error 400", str_contains($resPasswordVacio['json']['error'] ?? '', 'password: obligatorio'));

// Caso 3.3: JSON malformado
$resJsonMal = postLogin('{json-invalido}');
assertTest("JSON malformado retorna error 400", str_contains($resJsonMal['json']['error'] ?? '', 'Cuerpo JSON inválido'));

// 4. VERIFICACIÓN DE REGENERACIÓN Y HARDENING DE SESIÓN
echo "\n--- 4. Hardening de Sesión y Cookie ---\n";
$sessionConfig = require dirname(__DIR__) . '/config/session.php';
assertTest("Nombre de sesión institucional es 's3ih_session'", $sessionConfig['name'] === 's3ih_session');
assertTest("Cookie protegida con HttpOnly", $sessionConfig['httponly'] === true);
assertTest("SameSite configurado en Lax/Strict", in_array($sessionConfig['samesite'], ['Lax', 'Strict'], true));
assertTest("use_strict_mode habilitado", $sessionConfig['strict'] === true);

@unlink($runnerScript);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
