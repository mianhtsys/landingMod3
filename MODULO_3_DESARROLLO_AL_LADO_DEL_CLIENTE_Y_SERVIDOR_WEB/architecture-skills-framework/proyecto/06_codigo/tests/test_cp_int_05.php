<?php
declare(strict_types=1);

// Test automatizado de integración para CP-INT-05: Cierre y Validación Final Consolidada
// Workflow 06: Integración Final Didáctica (S3IH)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-INT-05: CIERRE Y VALIDACIÓN FINAL INTEGRADA (S3IH)\n";
echo "Workflow 06: Integración Final Didáctica\n";
echo "============================================================\n\n";

$phpBinary = PHP_BINARY;
$testSuites = [
    'CP-INT-01 (Login → Dashboard → Logout)' => __DIR__ . '/test_cp_int_01.php',
    'CP-INT-02 (Categorías: listar → crear → editar → inactivar)' => __DIR__ . '/test_cp_int_02.php',
    'CP-INT-03 (Productos: listar → crear → editar → inactivar)' => __DIR__ . '/test_cp_int_03.php',
    'CP-INT-04 (Seguridad: sesión, SQLi, XSS, CSRF, secretos, logs)' => __DIR__ . '/test_cp_int_04.php',
];

$results = [];
$allPassed = true;
$totalTestsRun = 0;
$totalPassedRun = 0;

foreach ($testSuites as $suiteName => $suiteFile) {
    echo ">>> Ejecutando {$suiteName}...\n";
    $output = shell_exec("\"{$phpBinary}\" \"{$suiteFile}\" 2>&1");
    
    // Extraer resultados del output: "RESULTADOS TOTALES: X / Y PASSED"
    if (preg_match('/RESULTADOS TOTALES:\s*(\d+)\s*\/\s*(\d+)\s*PASSED/i', (string)$output, $matches)) {
        $passed = (int) $matches[1];
        $total = (int) $matches[2];
        $totalPassedRun += $passed;
        $totalTestsRun += $total;
        $isOk = ($passed === $total && $total > 0);
        $results[$suiteName] = [
            'status' => $isOk ? 'PASSED' : 'FAILED',
            'passed' => $passed,
            'total' => $total,
        ];
        if (!$isOk) {
            $allPassed = false;
        }
        echo "    Resultado: {$passed}/{$total} PASSED " . ($isOk ? "[OK]" : "[FAIL]") . "\n\n";
    } else {
        $allPassed = false;
        $results[$suiteName] = [
            'status' => 'ERROR',
            'passed' => 0,
            'total' => 0,
            'error_preview' => mb_substr((string)$output, 0, 300)
        ];
        echo "    Resultado: ERROR de ejecución en {$suiteName}\n\n";
    }
}

// Validación de salud de la base de datos MySQL post-ejecución
$dbHealthy = Database::ping();
echo ">>> Verificación de integridad post-pruebas...\n";
echo "    Conexión MySQL activa y saludable: " . ($dbHealthy ? "[OK]" : "[FAIL]") . "\n\n";

echo "============================================================\n";
echo "RESUMEN CONSOLIDADO WORKFLOW 06 — INTEGRACIÓN FINAL DIDÁCTICA\n";
echo "============================================================\n";
foreach ($results as $name => $r) {
    printf(" - %-55s : %s (%d/%d)\n", $name, $r['status'], $r['passed'], $r['total']);
}
echo "------------------------------------------------------------\n";
echo "TOTAL ACUMULADO : {$totalPassedRun} / {$totalTestsRun} TESTS PASSED\n";
echo "ESTADO GLOBAL   : " . ($allPassed && $dbHealthy ? "EXITOSO (100% PASS)" : "FALLIDO") . "\n";
echo "============================================================\n";

if ($allPassed && $dbHealthy) {
    exit(0);
} else {
    exit(1);
}
