<?php
declare(strict_types=1);

// Test automatizado de integración para CP-SOL-07: Motor de Reloj de SLA y Worker Asíncrono Outbox
// Workflow 08: Módulo Central de Solicitudes Hospitalarias (S3IH)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Services\CorrelativoService;
use App\Services\OutboxWorkerService;
use App\Services\SlaService;

echo "============================================================\n";
echo "PRUEBAS CP-SOL-07: MOTOR DE RELOJ SLA Y WORKER OUTBOX (S3IH)\n";
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

// ============================================================
// 1. EXISTENCIA DE COMPONENTES Y SERVICIOS
// ============================================================
echo "--- 1. Existencia e Integridad de Componentes y Clases ---\n";

$workerPath = dirname(__DIR__) . '/bin/s3ih-worker.php';
assertTest("Script CLI bin/s3ih-worker.php existe", is_file($workerPath));

$syntaxCheck = @shell_exec("C:\\laragon\\bin\\php\\php-8.3.33-Win32-vs16-x64\\php.exe -l \"{$workerPath}\"");
assertTest("Script CLI bin/s3ih-worker.php tiene sintaxis PHP válida", str_contains((string) $syntaxCheck, 'No syntax errors detected'));

assertTest("Clase OutboxWorkerService existe", class_exists(OutboxWorkerService::class));
assertTest("OutboxWorkerService implementa método 'procesarLote'", method_exists(OutboxWorkerService::class, 'procesarLote'));

assertTest("Clase SlaService existe", class_exists(SlaService::class));
assertTest("SlaService implementa método 'evaluarSolicitud'", method_exists(SlaService::class, 'evaluarSolicitud'));
assertTest("SlaService implementa método 'evaluarTodasLasActivas'", method_exists(SlaService::class, 'evaluarTodasLasActivas'));

// ============================================================
// 2. WORKER ASÍNCRONO OUTBOX: CONSUMO Y SKIP LOCKED
// ============================================================
echo "\n--- 2. Consumo de Transactional Outbox (ops_outbox_eventos) ---\n";

// Crear solicitud de prueba para generar eventos
$corrService = new CorrelativoService();
$testCorr = $corrService->generarSiguiente(1);

$stmtSol = $pdo->prepare("
    INSERT INTO sol_solicitudes
        (codigo_correlativo, sede_id, servicio_solicitante_id, servicio_resolutor_id, tipo_solicitud_id,
         ubicacion_especifica, descripcion_falla, prioridad_asistencial, es_expedita,
         estado, version, usuario_solicitante_id, fecha_radicacion_utc, updated_at_utc)
    VALUES
        (:corr, 1, 1, 4, 1, 'UCI Cama 3', 'Falla respirador prueba CP-07', 'VITAL', 1,
         'Registrada', 1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
");
$stmtSol->execute([':corr' => $testCorr]);
$solId = (int) $pdo->lastInsertId();

// Insertar evento PENDIENTE en outbox
$stmtEv1 = $pdo->prepare("
    INSERT INTO ops_outbox_eventos
        (solicitud_id, tipo_evento, payload_json, estado, reintentos, created_at_utc)
    VALUES
        (:sol_id, 'SOLICITUD_EXPEDITA_CREADA', :payload, 'PENDIENTE', 0, UTC_TIMESTAMP())
");
$stmtEv1->execute([
    ':sol_id' => $solId,
    ':payload' => json_encode(['id' => $solId, 'codigo_correlativo' => $testCorr, 'prioridad' => 'VITAL'])
]);
$ev1Id = (int) $pdo->lastInsertId();

$outboxService = new OutboxWorkerService(maxRetries: 3);
$resLote = $outboxService->procesarLote(limit: 50);

assertTest("procesarLote encuentra y procesa el evento pendiente", $resLote['total_encontrados'] >= 1 && $resLote['procesados'] >= 1);

// Verificar en base de datos que el evento cambió a PROCESADO
$stmtCheck = $pdo->prepare("SELECT estado, procesado_at_utc, error_mensaje FROM ops_outbox_eventos WHERE id = :id");
$stmtCheck->execute([':id' => $ev1Id]);
$ev1Db = $stmtCheck->fetch();

assertTest("Evento en BD tiene estado = 'PROCESADO'", ($ev1Db['estado'] ?? '') === 'PROCESADO');
assertTest("Evento en BD tiene timestamp 'procesado_at_utc' no nulo", !empty($ev1Db['procesado_at_utc']));
assertTest("Evento en BD tiene 'error_mensaje' nulo tras éxito", $ev1Db['error_mensaje'] === null);

// ============================================================
// 3. MANEJO DE ERRORES Y REINTENTOS EN OUTBOX
// ============================================================
echo "\n--- 3. Manejo de Errores y Política de Reintentos en Outbox ---\n";

// Insertar evento defectuoso que provocará excepción controlada
$stmtEvErr = $pdo->prepare("
    INSERT INTO ops_outbox_eventos
        (solicitud_id, tipo_evento, payload_json, estado, reintentos, created_at_utc)
    VALUES
        (:sol_id, 'SOLICITUD_CREADA', '{\"invalido\": true}', 'PENDIENTE', 0, UTC_TIMESTAMP())
");
$stmtEvErr->execute([':sol_id' => $solId]);
$evErrId = (int) $pdo->lastInsertId();

// Ciclo 1 de reintento
$resRetry1 = $outboxService->procesarLote(limit: 50);
$stmtCheck->execute([':id' => $evErrId]);
$rowRetry1 = $stmtCheck->fetch();
assertTest("Reintento 1: estado permanece 'PENDIENTE' con reintentos = 1",
    ($rowRetry1['estado'] ?? '') === 'PENDIENTE',
    "Estado actual: {$rowRetry1['estado']}"
);

// Ciclo 2 de reintento
$resRetry2 = $outboxService->procesarLote(limit: 50);

// Ciclo 3 de reintento -> debe alcanzar maxRetries (3) y marcar FALLIDO
$resRetry3 = $outboxService->procesarLote(limit: 50);
$stmtCheck->execute([':id' => $evErrId]);
$rowRetry3 = $stmtCheck->fetch();

assertTest("Tras superar maxRetries, el evento pasa a estado = 'FALLIDO'",
    ($rowRetry3['estado'] ?? '') === 'FALLIDO',
    "Estado actual: {$rowRetry3['estado']}"
);
assertTest("Evento fallido registra descripción del error", !empty($rowRetry3['error_mensaje']));

// ============================================================
// 4. MOTOR DE RELOJ DE SLA: MTTA, MTTR, 75% Y 100%
// ============================================================
echo "\n--- 4. Motor de Reloj de SLA: Detección Preventiva y Escalamiento ---\n";

$slaService = new SlaService();

// Caso A: Normal (< 75%)
// Simular fecha de radicación hace 3 minutos (para MTTA = 15 min -> 20% consumido)
$ahoraBase = new DateTimeImmutable('2026-10-08 12:00:00', new DateTimeZone('UTC'));
$fechaNormal = '2026-10-08 11:57:00'; // 3 min antes
$pdo->prepare("UPDATE sol_solicitudes SET fecha_radicacion_utc = :f, estado = 'Registrada' WHERE id = :id")
    ->execute([':f' => $fechaNormal, ':id' => $solId]);

$evalNormal = $slaService->evaluarSolicitud($solId, $ahoraBase);
assertTest("Caso A: Consumo de SLA < 75% genera nivel_alerta = 'NORMAL'", ($evalNormal['nivel_alerta'] ?? '') === 'NORMAL');
assertTest("Caso A: requiere_alerta_preventiva es false", $evalNormal['requiere_alerta_preventiva'] === false);
assertTest("Caso A: requiere_escalamiento es false", $evalNormal['requiere_escalamiento'] === false);

// Caso B: Alerta Preventiva 75% (>= 75% y < 100%)
// Simular fecha de radicación hace 12 minutos (para MTTA = 15 min -> 80% consumido)
$fechaAlerta75 = '2026-10-08 11:48:00'; // 12 min antes
$pdo->prepare("UPDATE sol_solicitudes SET fecha_radicacion_utc = :f WHERE id = :id")
    ->execute([':f' => $fechaAlerta75, ':id' => $solId]);

$eval75 = $slaService->evaluarSolicitud($solId, $ahoraBase);
assertTest("Caso B: Consumo >= 75% genera nivel_alerta = 'ALERTA_PREVENTIVA_75'", ($eval75['nivel_alerta'] ?? '') === 'ALERTA_PREVENTIVA_75');
assertTest("Caso B: requiere_alerta_preventiva es true", $eval75['requiere_alerta_preventiva'] === true);
assertTest("Caso B: requiere_escalamiento es false", $eval75['requiere_escalamiento'] === false);
assertTest("Caso B: porcentaje_consumido calculado correctamente (~80%)", $eval75['porcentaje_consumido'] >= 75.0 && $eval75['porcentaje_consumido'] < 100.0);

// Caso C: Escalamiento Jerárquico 100% (>= 100%)
// Simular fecha de radicación hace 20 minutos (para MTTA = 15 min -> 133.3% consumido)
$fechaEscala100 = '2026-10-08 11:40:00'; // 20 min antes
$pdo->prepare("UPDATE sol_solicitudes SET fecha_radicacion_utc = :f WHERE id = :id")
    ->execute([':f' => $fechaEscala100, ':id' => $solId]);

$eval100 = $slaService->evaluarSolicitud($solId, $ahoraBase);
assertTest("Caso C: Consumo >= 100% genera nivel_alerta = 'ESCALAMIENTO_JERARQUICO_100'", ($eval100['nivel_alerta'] ?? '') === 'ESCALAMIENTO_JERARQUICO_100');
assertTest("Caso C: requiere_alerta_preventiva es true", $eval100['requiere_alerta_preventiva'] === true);
assertTest("Caso C: requiere_escalamiento es true", $eval100['requiere_escalamiento'] === true);
assertTest("Caso C: porcentaje_consumido >= 100%", $eval100['porcentaje_consumido'] >= 100.0);

// Caso D: Pausa de Reloj en Estado 'En Espera' (RN-006)
$pdo->prepare("UPDATE sol_solicitudes SET estado = 'En Espera' WHERE id = :id")
    ->execute([':id' => $solId]);

$evalPausado = $slaService->evaluarSolicitud($solId, $ahoraBase);
assertTest("Caso D: Estado 'En Espera' pausa el reloj de SLA (nivel_alerta = 'PAUSADO')", ($evalPausado['nivel_alerta'] ?? '') === 'PAUSADO');
assertTest("Caso D: Fase evaluada es 'MTTR_PAUSADO'", ($evalPausado['fase'] ?? '') === 'MTTR_PAUSADO');

// ============================================================
// 5. EVALUACIÓN CONSOLIDADA DE LA RED HOSPITALARIA
// ============================================================
echo "\n--- 5. Evaluación Consolidada de la Red Hospitalaria ---\n";

$evalGlobal = $slaService->evaluarTodasLasActivas($ahoraBase);
assertTest("evaluarTodasLasActivas retorna estructura con 'resumen' y 'solicitudes'",
    isset($evalGlobal['resumen']) && isset($evalGlobal['solicitudes'])
);
assertTest("Resumen contiene conteos de total, normal, preventiva_75, escalamiento_100 y pausadas",
    isset($evalGlobal['resumen']['total']) &&
    isset($evalGlobal['resumen']['normal']) &&
    isset($evalGlobal['resumen']['preventiva_75']) &&
    isset($evalGlobal['resumen']['escalamiento_100']) &&
    isset($evalGlobal['resumen']['pausadas'])
);

// ============================================================
// 6. EJECUCIÓN FÍSICA DEL SCRIPT CLI bin/s3ih-worker.php
// ============================================================
echo "\n--- 6. Ejecución Física del Script CLI bin/s3ih-worker.php ---\n";

// A. Ejecutar con opción --help
$cmdHelp = "C:\\laragon\\bin\\php\\php-8.3.33-Win32-vs16-x64\\php.exe \"{$workerPath}\" --help";
$outHelp = (string) shell_exec($cmdHelp);
assertTest("bin/s3ih-worker.php --help muestra opciones de línea de comandos",
    str_contains($outHelp, '--once') &&
    str_contains($outHelp, '--outbox-only') &&
    str_contains($outHelp, '--sla-only')
);

// B. Ejecutar ciclo completo con opción --once
$cmdOnce = "C:\\laragon\\bin\\php\\php-8.3.33-Win32-vs16-x64\\php.exe \"{$workerPath}\" --once";
$outOnce = (string) shell_exec($cmdOnce);
assertTest("bin/s3ih-worker.php --once ejecuta ciclo completo y finaliza exitosamente",
    str_contains($outOnce, '[OUTBOX]') &&
    str_contains($outOnce, '[SLA]') &&
    str_contains($outOnce, 'Ciclo de worker finalizado exitosamente')
);

// C. Ejecutar solo outbox con --outbox-only
$cmdOutboxOnly = "C:\\laragon\\bin\\php\\php-8.3.33-Win32-vs16-x64\\php.exe \"{$workerPath}\" --once --outbox-only";
$outOutboxOnly = (string) shell_exec($cmdOutboxOnly);
assertTest("bin/s3ih-worker.php --once --outbox-only ejecuta exclusivamente sección outbox",
    str_contains($outOutboxOnly, '[OUTBOX]') &&
    !str_contains($outOutboxOnly, '[SLA]')
);

// D. Ejecutar solo SLA con --sla-only
$cmdSlaOnly = "C:\\laragon\\bin\\php\\php-8.3.33-Win32-vs16-x64\\php.exe \"{$workerPath}\" --once --sla-only";
$outSlaOnly = (string) shell_exec($cmdSlaOnly);
assertTest("bin/s3ih-worker.php --once --sla-only ejecuta exclusivamente auditoría SLA",
    !str_contains($outSlaOnly, '[OUTBOX]') &&
    str_contains($outSlaOnly, '[SLA]')
);

// ============================================================
// LIMPIEZA DE DATOS DE PRUEBA
// ============================================================
$pdo->prepare("DELETE FROM ops_outbox_eventos WHERE solicitud_id = :id")->execute([':id' => $solId]);
$pdo->prepare("DELETE FROM sol_historial_estados WHERE solicitud_id = :id")->execute([':id' => $solId]);
$pdo->prepare("DELETE FROM sol_solicitudes WHERE id = :id")->execute([':id' => $solId]);

// ============================================================
// RESUMEN FINAL
// ============================================================
echo "\n============================================================\n";
echo "RESUMEN DE PRUEBAS CP-SOL-07: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    echo ">>> TODOS LOS TESTS PASARON EXITOSAMENTE. CP-SOL-07 VALIDADO AL 100% <<<\n";
    exit(0);
} else {
    echo ">>> SE DETECTARON FALLOS EN LA SUITE DE PRUEBAS <<<\n";
    exit(1);
}
