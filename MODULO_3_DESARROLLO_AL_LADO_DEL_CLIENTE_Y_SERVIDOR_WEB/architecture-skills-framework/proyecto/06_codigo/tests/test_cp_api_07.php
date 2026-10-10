<?php
declare(strict_types=1);

// Test automatizado para CP-API-07: DELETE lógico (estado='inactivo', nunca DELETE FROM)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-API-07: DELETE LÓGICO /api/v1/categorias/{id}\n";
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
$runnerScript = __DIR__ . '/runner_exec.php';

// Fixture con categorías activas
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("TRUNCATE TABLE cat_categoria");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

$pdo->exec("
    INSERT INTO cat_categoria (id, nombre, categoria_padre_id, estado, user_create, user_update)
    VALUES 
    (500, 'Equipamiento Desfibrilador Móvil', NULL, 'activo', 1, 1),
    (501, 'Electrodos Desechables Adulto', 500, 'activo', 1, 1)
");

function callDelete(string $uri): array
{
    global $phpBinary, $runnerScript;
    $cmd = "\"{$phpBinary}\" \"{$runnerScript}\" DELETE {$uri} 2>&1";
    $output = shell_exec($cmd);
    $json = json_decode((string)$output, true);
    return ['output' => (string)$output, 'json' => $json];
}

// 1. INACTIVACIÓN LÓGICA EXITOSA (HTTP 200 OK)
echo "--- 1. Prueba de Inactivación Lógica Exitosa (HTTP 200 OK) ---\n";
$resDel = callDelete('/api/v1/categorias/500');

assertTest("DELETE responde JSON válido", is_array($resDel['json']));
assertTest("success es true en inactivación lógica", ($resDel['json']['success'] ?? false) === true);
assertTest("data.id se mantiene en 500", ($resDel['json']['data']['id'] ?? 0) === 500);
assertTest("data.estado cambió a 'inactivo'", ($resDel['json']['data']['estado'] ?? '') === 'inactivo');

// Verificación directa en base de datos: el registro NO fue eliminado físicamente
$stmt = $pdo->prepare("SELECT COUNT(*), estado FROM cat_categoria WHERE id = 500 GROUP BY estado");
$stmt->execute();
$rowDb = $stmt->fetch();
assertTest("El registro sigue existiendo en BD (COUNT = 1, sin DELETE FROM)", ($rowDb['COUNT(*)'] ?? 0) == 1);
assertTest("El campo estado en BD es exactamente 'inactivo'", ($rowDb['estado'] ?? '') === 'inactivo');

// 2. IDEMPOTENCIA (DOBLE DELETE → 200 OK)
echo "\n--- 2. Prueba de Idempotencia (Doble DELETE → 200 OK) ---\n";
$resDoble = callDelete('/api/v1/categorias/500');
assertTest("Segundo DELETE retorna success: true (idempotente)", ($resDoble['json']['success'] ?? false) === true);
assertTest("data.estado sigue siendo 'inactivo'", ($resDoble['json']['data']['estado'] ?? '') === 'inactivo');

// 3. CASO INEXISTENTE (HTTP 404 NOT FOUND)
echo "\n--- 3. Prueba de Inexistente (HTTP 404 Not Found) ---\n";
$resInexistente = callDelete('/api/v1/categorias/999999');
assertTest("ID inexistente retorna success: false", ($resInexistente['json']['success'] ?? true) === false);
assertTest("Informa 'Categoría no encontrada'", str_contains($resInexistente['json']['error'] ?? '', 'Categoría no encontrada'));

// 4. CASO ID INVÁLIDO (HTTP 400 BAD REQUEST)
echo "\n--- 4. Prueba de ID Inválido (HTTP 400 Bad Request) ---\n";
$resInvalido = callDelete('/api/v1/categorias/xyz');
assertTest("ID alfanumérico retorna success: false", ($resInvalido['json']['success'] ?? true) === false);
assertTest("Informa 'ID inválido'", str_contains($resInvalido['json']['error'] ?? '', 'ID inválido'));

// 5. AUDITORÍA DE CÓDIGO FUENTE (Regla RN-012: Cero sentencias SQL destructivas)
echo "\n--- 5. Verificación de Inmutabilidad Hospitalaria (RN-012) ---\n";
$repoCode = file_get_contents(dirname(__DIR__) . '/app/Repositories/CategoriaRepository.php');
$codeWithoutComments = preg_replace('#//.*$|/\*.*?\*/#m', '', (string)$repoCode);
assertTest("El código ejecutable del repositorio NO contiene sentencias 'DELETE FROM'", !stripos((string)$codeWithoutComments, 'DELETE FROM'));
assertTest("El repositorio usa exclusivamente UPDATE SET estado = 'inactivo'", str_contains((string)$repoCode, "UPDATE") && str_contains((string)$repoCode, "estado = 'inactivo'"));

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
