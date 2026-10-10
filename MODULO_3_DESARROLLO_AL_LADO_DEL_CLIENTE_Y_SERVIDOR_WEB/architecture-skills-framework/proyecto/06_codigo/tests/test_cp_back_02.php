<?php
declare(strict_types=1);

// Test automatizado para CP-BACK-02: Crear tools/create_demo_user.php

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-BACK-02: CREAR USUARIO DEMO (tools/create_demo_user.php)\n";
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
$pdo = Database::conn();
$scriptTool = dirname(__DIR__) . '/tools/create_demo_user.php';

// 1. Verificación de Variables en .env
echo "--- 1. Verificación de Configuración en .env ---\n";
assertTest("Script create_demo_user.php existe", is_file($scriptTool));
$demoName = envRequired('DEMO_USER_NAME');
$demoEmail = envRequired('DEMO_USER_EMAIL');
$demoPassword = envRequired('DEMO_USER_PASSWORD');
assertTest("DEMO_USER_NAME configurado para S3IH", str_contains($demoName, 'Biomédica') || str_contains($demoName, 'Supervisor'));
assertTest("DEMO_USER_EMAIL configurado en dominio institucional", str_contains($demoEmail, '@s3ih.salud.gob'));
assertTest("DEMO_USER_PASSWORD no vacío y seguro", strlen($demoPassword) >= 8);

// 2. Ejecución del Script y Salida Segura
echo "\n--- 2. Ejecución de la Herramienta CLI ---\n";
$output = shell_exec("\"{$phpBinary}\" \"{$scriptTool}\" 2>&1");

assertTest("Salida confirma usuario demo preparado", str_contains((string)$output, 'Usuario demo preparado: ' . $demoEmail));
assertTest("Salida indica que la contraseña no se muestra", str_contains((string)$output, 'La contraseña no se muestra'));
assertTest("La contraseña en texto plano NO se filtra en la salida", !str_contains((string)$output, $demoPassword));

// 3. Verificación de Persistencia y Criptografía en Base de Datos
echo "\n--- 3. Verificación Criptográfica en Base de Datos ---\n";
$stmt = $pdo->prepare("SELECT id, nombre, email, estado, hash_password FROM auth_usuario WHERE email = :email");
$stmt->execute(['email' => $demoEmail]);
$userDb = $stmt->fetch();

assertTest("Usuario persistido existe en BD", is_array($userDb));
assertTest("Nombre en BD coincide", ($userDb['nombre'] ?? '') === $demoName);
assertTest("Estado es 'activo'", ($userDb['estado'] ?? '') === 'activo');
assertTest("Hash utiliza algoritmo Argon2id (\$argon2id\$)", str_starts_with($userDb['hash_password'] ?? '', '$argon2id$'));
assertTest("password_verify valida exitosamente la contraseña demo", password_verify($demoPassword, $userDb['hash_password'] ?? ''));
assertTest("password_verify rechaza contraseña errónea", !password_verify('ClaveEquivocada123', $userDb['hash_password'] ?? ''));

// 4. Verificación de Idempotencia
echo "\n--- 4. Verificación de Idempotencia (Sin Duplicación) ---\n";
$outputReRun = shell_exec("\"{$phpBinary}\" \"{$scriptTool}\" 2>&1");
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM auth_usuario WHERE email = :email");
$stmtCount->execute(['email' => $demoEmail]);
$count = (int)$stmtCount->fetchColumn();

assertTest("Re-ejecución no crea duplicados (COUNT = 1)", $count === 1);
assertTest("Re-ejecución retorna mensaje de éxito", str_contains((string)$outputReRun, 'Usuario demo preparado'));

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
