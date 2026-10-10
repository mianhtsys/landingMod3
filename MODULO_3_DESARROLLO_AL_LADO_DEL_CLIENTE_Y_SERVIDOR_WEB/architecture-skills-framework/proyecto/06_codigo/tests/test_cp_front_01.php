<?php
declare(strict_types=1);

// Test automatizado para CP-FRONT-01: Pantalla de Login (S3IH)
// Valida HTML semántico, formulario accesible, protección/redirección de sesión y assets estáticos.

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-FRONT-01: PANTALLA DE LOGIN (S3IH)\n";
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

$phpBinary = PHP_BINARY;
$runnerScript = __DIR__ . '/runner_front_login.php';

function callWeb(string $uri, ?string $sessionCookie = null): array
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

// 1. RENDERIZADO DE LA PANTALLA DE LOGIN SIN SESIÓN
echo "--- 1. Renderizado de Pantalla /login (Sin Sesión) ---\n";
$resLogin = callWeb('/login', null);
$html = $resLogin['output'];

assertTest("La salida contiene la estructura HTML básica (<!DOCTYPE html>)", str_contains($html, '<!DOCTYPE html>'));
assertTest("El idioma está fijado en español (lang=\"es\")", str_contains($html, '<html lang="es">'));
assertTest("Contiene título institucional S3IH en <title>", str_contains($html, 'Iniciar sesión') && str_contains($html, 'S3IH'));
assertTest("Tarjeta contenedora semántica <main class=\"auth-card\">", str_contains($html, '<main class="auth-card">'));
assertTest("Encabezado <h1> con texto 'Iniciar sesión'", str_contains($html, '<h1>Iniciar sesión</h1>'));
assertTest("Subtítulo contextual institucional de red hospitalaria", str_contains($html, 'Red Hospitalaria Interconectada'));
assertTest("Formulario con id 'login-form'", str_contains($html, '<form id="login-form"'));
assertTest("Campo de correo electrónico accesible (#email, type=email, required)", str_contains($html, 'id="email"') && str_contains($html, 'type="email"') && str_contains($html, 'required'));
assertTest("Campo de contraseña seguro (#password, type=password, required)", str_contains($html, 'id="password"') && str_contains($html, 'type="password"') && str_contains($html, 'required'));
assertTest("Elemento para errores accesible con role=\"alert\" (#login-error)", str_contains($html, 'id="login-error"') && str_contains($html, 'role="alert"') && str_contains($html, 'hidden'));
assertTest("Botón de envío con id 'login-submit'", str_contains($html, 'id="login-submit"') && str_contains($html, 'type="submit"'));
assertTest("Aviso legal y regulatorio Zero-PHI visible", str_contains($html, 'Zero-PHI'));
assertTest("Vinculación de módulo JS login.js (type=\"module\")", str_contains($html, '<script type="module" src="/assets/js/modules/login.js">'));

// 2. REDIRECCIÓN CON SESIÓN ACTIVA
echo "\n--- 2. Redirección Inmediata con Sesión Activa ---\n";
$resConSesion = callWeb('/login', 'sesion_activa_dummy_123');
// Cuando hay sesión activa, header('Location: /dashboard') y exit; en CLI no imprime el body de login
assertTest("Con sesión activa redirige y NO renderiza formulario de login", !str_contains($resConSesion['output'], '<form id="login-form"'));

// 3. INTEGRIDAD DE ASSETS ESTÁTICOS VINCULADOS
echo "\n--- 3. Integridad de Assets Estáticos Vinculados ---\n";
$publicDir = dirname(__DIR__) . '/public';

$assetsEsperados = [
    '/assets/css/variables.css',
    '/assets/css/layout.css',
    '/assets/css/components.css',
    '/assets/css/forms.css',
    '/assets/css/responsive.css',
    '/assets/js/modules/login.js',
    '/assets/js/api/auth-api.js'
];

foreach ($assetsEsperados as $asset) {
    $fullPath = $publicDir . $asset;
    $existe = is_file($fullPath) && filesize($fullPath) > 0;
    assertTest("Asset estático existe y no está vacío: {$asset}", $existe);
}

// 4. VERIFICACIÓN DE CONSUMO API EN EL MÓDULO JS
echo "\n--- 4. Consumo API REST desde Módulo JS ---\n";
$loginJsContent = file_get_contents($publicDir . '/assets/js/modules/login.js');
$authApiContent = file_get_contents($publicDir . '/assets/js/api/auth-api.js');

assertTest("login.js importa módulo de API de auth", str_contains($loginJsContent, "import { login } from '../api/auth-api.js'"));
assertTest("auth-api.js realiza petición POST a /api/v1/auth/login", str_contains($authApiContent, "'/api/v1/auth/login'"));
assertTest("auth-api.js envía credentials: 'same-origin'", str_contains($authApiContent, "credentials: 'same-origin'"));

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
