<?php
declare(strict_types=1);

// Test automatizado de integración para CP-SOL-01: Migración Física y Semillas Hospitalarias
// Workflow 08: Módulo Central de Solicitudes Hospitalarias (S3IH)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;

echo "============================================================\n";
echo "PRUEBAS CP-SOL-01: MIGRACIÓN FÍSICA Y SEMILLAS HOSPITALARIAS (S3IH)\n";
echo "Workflow 08: Módulo Central de Solicitudes Hospitalarias\n";
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

// 1. Ejecución de la migración DDL y semillas
$migrationFile = dirname(__DIR__) . '/database/03_modulo_solicitudes_hospitalarias.sql';
assertTest("Archivo de migración DDL existe en database/", file_exists($migrationFile));

$sqlContent = file_get_contents($migrationFile);
try {
    $pdo->exec($sqlContent);
    assertTest("Script DDL 03_modulo_solicitudes_hospitalarias.sql ejecutado sin excepciones en MySQL", true);
} catch (Throwable $e) {
    assertTest("Script DDL ejecutado sin excepciones en MySQL", false, $e->getMessage());
}

// 2. Verificación de existencia de tablas estructurales
$tablesRequired = [
    'org_sedes',
    'org_servicios',
    'cat_solicitud_tipos',
    'sla_calendarios',
    'sla_politicas',
    'sol_solicitudes',
    'sol_historial_estados',
    'ops_outbox_eventos'
];

$existingTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tablesRequired as $table) {
    assertTest("Tabla física '{$table}' existe en s3ih_db", in_array($table, $existingTables, true));
}

// 3. Verificación de datos de la Red de 4 Hospitales (org_sedes)
$stmtSedes = $pdo->query("SELECT codigo_institucional, nombre, es_central, estado FROM org_sedes ORDER BY id");
$sedes = $stmtSedes->fetchAll(PDO::FETCH_ASSOC);

assertTest("Exactamente 4 sedes hospitalarias registradas en org_sedes", count($sedes) === 4);
$codigos = array_column($sedes, 'codigo_institucional');
assertTest("Códigos H1, H2, H3, H4 presentes", $codigos === ['H1', 'H2', 'H3', 'H4']);

$h1 = $sedes[0] ?? [];
assertTest("Sede H1 es designada como Hospital Central", ($h1['es_central'] ?? 0) == 1);

// 4. Verificación de Servicios Asistenciales y Resolutores (org_servicios)
$stmtServicios = $pdo->query("SELECT codigo, nombre, tipo_servicio FROM org_servicios WHERE sede_id = 1");
$servicios = $stmtServicios->fetchAll(PDO::FETCH_ASSOC);

assertTest("Servicios clínicos y resolutores configurados para H1", count($servicios) >= 6);

$tiposServicios = array_column($servicios, 'tipo_servicio');
assertTest("Coexistencia de servicios tipo ASISTENCIAL y RESOLUTOR", in_array('ASISTENCIAL', $tiposServicios, true) && in_array('RESOLUTOR', $tiposServicios, true));

// 5. Verificación de Tipos de Solicitud y Políticas SLA
$stmtTipos = $pdo->query("SELECT codigo_tipo, prioridad_sugerida FROM cat_solicitud_tipos");
$tipos = $stmtTipos->fetchAll(PDO::FETCH_ASSOC);
assertTest("Catálogo tipificado de fallas/solicitudes cargado", count($tipos) >= 5);

$stmtSla = $pdo->query("SELECT tipo_solicitud_id, prioridad_asistencial, tiempo_max_respuesta_mtta_min, tiempo_max_resolucion_mttr_min FROM sla_politicas");
$politicasSla = $stmtSla->fetchAll(PDO::FETCH_ASSOC);
assertTest("Políticas de SLA dual (MTTA y MTTR) registradas", count($politicasSla) >= 5);

// Verificar SLA de soporte vital (Ventilador UCI: MTTA 15 min, MTTR 60 min)
$stmtVent = $pdo->query("
    SELECT p.tiempo_max_respuesta_mtta_min, p.tiempo_max_resolucion_mttr_min
    FROM sla_politicas p
    JOIN cat_solicitud_tipos t ON p.tipo_solicitud_id = t.id
    WHERE t.codigo_tipo = 'BIO-VENT-FALLA' AND p.prioridad_asistencial = 'VITAL'
");
$ventSla = $stmtVent->fetch(PDO::FETCH_ASSOC);
assertTest("SLA Soporte Vital Ventilador UCI: MTTA <= 15 min", ($ventSla['tiempo_max_respuesta_mtta_min'] ?? 0) == 15);
assertTest("SLA Soporte Vital Ventilador UCI: MTTR <= 60 min", ($ventSla['tiempo_max_resolucion_mttr_min'] ?? 0) == 60);

// 6. Verificación de Integridad Referencial y Prohibición de Cascadas Destructivas
// Intentar borrar una sede con servicios asociados debe fallar con error de clave foránea RESTRICT (RN-012)
try {
    $pdo->exec("DELETE FROM org_sedes WHERE codigo_institucional = 'H1'");
    $restrictOk = false;
} catch (PDOException $e) {
    $restrictOk = true; // Error 1451: Cannot delete or update a parent row: a foreign key constraint fails
}
assertTest("FK ON DELETE RESTRICT protege sedes contra borrado en cascada (RN-012)", $restrictOk);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
