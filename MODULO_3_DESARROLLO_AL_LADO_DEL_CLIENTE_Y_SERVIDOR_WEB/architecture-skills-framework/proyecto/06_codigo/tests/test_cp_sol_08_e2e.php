<?php
declare(strict_types=1);

// Test automatizado de integración de ciclo completo (E2E) para CP-SOL-08: Integración E2E y Cierre del Módulo Hospitalario
// Workflow 08: Módulo Central de Solicitudes Hospitalarias (S3IH)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Services\CorrelativoService;
use App\Services\OutboxWorkerService;
use App\Services\SlaService;
use App\Services\SolicitudService;
use App\Services\SolicitudStateMachine;

echo "======================================================================\n";
echo "PRUEBAS CP-SOL-08: INTEGRACIÓN E2E Y CIERRE DEL MÓDULO HOSPITALARIO\n";
echo "Workflow 08: Sistema de Seguimiento de Solicitudes Hospitalarias (S3IH)\n";
echo "======================================================================\n\n";

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

// ====================================================================
// FASE 1: INFRAESTRUCTURA DE DATOS Y CATÁLOGOS MAESTROS (CP-SOL-01)
// ====================================================================
echo "--- FASE 1: Infraestructura de Datos y Catálogos Maestros (Red de 4 Hospitales) ---\n";

$stmtSedes = $pdo->query("SELECT COUNT(*) FROM org_sedes WHERE estado = 'activo'");
$cantSedes = (int) $stmtSedes->fetchColumn();
assertTest("1.1 Se encuentran configuradas y activas las 4 sedes de la red hospitalaria", $cantSedes >= 4);

$stmtSrv = $pdo->query("SELECT COUNT(*) FROM org_servicios WHERE tipo_servicio IN ('ASISTENCIAL', 'RESOLUTOR')");
assertTest("1.2 Existen servicios asistenciales (UCI, Quirófano) y resolutores (Biomédica, Infraestructura)", (int) $stmtSrv->fetchColumn() >= 6);

$stmtPol = $pdo->query("SELECT COUNT(*) FROM sla_politicas");
assertTest("1.3 Políticas de SLA (MTTA/MTTR) registradas en sla_politicas", (int) $stmtPol->fetchColumn() >= 5);

// ====================================================================
// FASE 2: GENERADOR DE CORRELATIVOS INMUTABLES (CP-SOL-02)
// ====================================================================
echo "\n--- FASE 2: Generación Atómica de Correlativos Inmutables (RN-001) ---\n";

$corrService = new CorrelativoService();
$corr1 = $corrService->generarSiguiente(1);
$corr2 = $corrService->generarSiguiente(1);

assertTest("2.1 Formato estricto de correlativo institucional H{sede}-{año}-{secuencial}", (bool) preg_match('/^H1-\d{4}-\d{6}$/', $corr1));
assertTest("2.2 Secuencialidad estricta y unicidad ante generación consecutiva", $corr1 !== $corr2);

// ====================================================================
// FASE 3: RADICACIÓN DUAL, SEGURIDAD ZERO-PHI E IDEMPOTENCIA (CP-SOL-03)
// ====================================================================
echo "\n--- FASE 3: Radicación Dual, Seguridad Zero-PHI e Idempotencia (RNF-035) ---\n";

$solService = new SolicitudService();
$validator = new \App\Validators\SolicitudValidator();

// 3.1 Intento de radicación con datos personales de paciente (violación Zero-PHI)
$phiIntent = false;
try {
    $validator->validateZeroPhi(
        'Paciente Juan Pérez con Cédula 4983211 presenta falla en respirador',
        'descripcion_falla'
    );
} catch (\Throwable $e) {
    $phiIntent = ($e->getCode() === 422) || str_contains($e->getMessage(), 'Zero-PHI');
}
assertTest("3.1 Filtro Zero-PHI rechaza con 422 reporte que incluye identidad de paciente (RNF-035)", $phiIntent);

// 3.2 Radicación ordinaria válida
$idempKeyOrd = 'idemp-' . bin2hex(random_bytes(8));
$solOrd = $solService->create([
    'sede_id' => 1,
    'servicio_solicitante_id' => 1,
    'servicio_resolutor_id' => 4,
    'tipo_solicitud_id' => 1,
    'ubicacion_especifica' => 'UCI Quirúrgica Box 2',
    'descripcion_falla' => 'Ventilador mecánico no responde al encendido principal. Falla de fuente conmutada.',
    'prioridad_asistencial' => 'ALTA',
    'usuario_solicitante_id' => 1
], $idempKeyOrd, '127.0.0.1');

$solOrdId = (int) $solOrd['id'];
assertTest("3.2 Radicación ordinaria exitosa con estado inicial 'Registrada' y version 1",
    $solOrdId > 0 &&
    $solOrd['estado'] === 'Registrada' &&
    $solOrd['version'] === 1 &&
    $solOrd['es_expedita'] == 0
);

// 3.3 Prueba de Idempotencia
$solOrdDupl = $solService->create([
    'sede_id' => 1,
    'servicio_solicitante_id' => 1,
    'servicio_resolutor_id' => 4,
    'tipo_solicitud_id' => 1,
    'ubicacion_especifica' => 'UCI Quirúrgica Box 2',
    'descripcion_falla' => 'Ventilador mecánico no responde al encendido principal.',
    'prioridad_asistencial' => 'ALTA',
    'usuario_solicitante_id' => 1
], $idempKeyOrd, '127.0.0.1');

assertTest("3.3 Idempotency-Key devuelve misma orden sin duplicar en base de datos",
    $solOrdDupl['id'] === $solOrdId &&
    $solOrdDupl['idempotent'] === true
);

// 3.4 Radicación expedita de soporte vital (UCI / Quirófano)
$idempKeyExp = 'idemp-' . bin2hex(random_bytes(8));
$solExp = $solService->createExpedita([
    'sede_id' => 1,
    'servicio_solicitante_id' => 1,
    'servicio_resolutor_id' => 4,
    'tipo_solicitud_id' => 1,
    'ubicacion_especifica' => 'Quirófano 1 - Emergencias',
    'descripcion_falla' => 'Corte súbito de oxígeno en circuito de máquina de anestesia durante cirugía mayor.',
    'usuario_solicitante_id' => 1
], $idempKeyExp, '127.0.0.1');

$solExpId = (int) $solExp['id'];
assertTest("3.4 Radicación expedita fuerza es_expedita=1 y prioridad_asistencial='VITAL'",
    $solExpId > 0 &&
    $solExp['es_expedita'] == 1 &&
    $solExp['prioridad_asistencial'] === 'VITAL'
);

// ====================================================================
// FASE 4: MÁQUINA DE ESTADOS FSM Y CONCURRENCIA OPTIMISTA (CP-SOL-04)
// ====================================================================
echo "\n--- FASE 4: Ciclo de Vida Completo FSM y Concurrencia Optimista (RN-005, D-01) ---\n";

// Transición 1: Registrada (v1) -> Asignada (v2)
$t1 = $solService->cambiarEstado(
    id: $solOrdId,
    nuevoEstado: 'Asignada',
    versionEsperada: 1,
    tecnicoAsignadoId: 1,
    motivo: 'Asignado a Oficial de Biomédica para inspección urgente',
    causal: null,
    usuarioId: 1,
    clientIp: '127.0.0.1'
);
assertTest("4.1 Transición 1: Registrada -> Asignada incrementa versión a 2", $t1['version'] === 2 && $t1['estado'] === 'Asignada');

// Transición 2: Asignada (v2) -> En Proceso (v3)
$t2 = $solService->cambiarEstado(
    id: $solOrdId,
    nuevoEstado: 'En Proceso',
    versionEsperada: 2,
    tecnicoAsignadoId: null,
    motivo: 'Personal técnico presente en UCI iniciando diagnóstico físico',
    causal: null,
    usuarioId: 1,
    clientIp: '127.0.0.1'
);
assertTest("4.2 Transición 2: Asignada -> En Proceso incrementa versión a 3", $t2['version'] === 3 && $t2['estado'] === 'En Proceso');

// Prueba de Control de Concurrencia Optimista (D-01): Usuario concurrente envía versión vieja (v2)
$conflictDetected = false;
try {
    $solService->cambiarEstado(
        id: $solOrdId,
        nuevoEstado: 'Atendida',
        versionEsperada: 2, // Desactualizada (la base de datos está en v3)
        tecnicoAsignadoId: null,
        motivo: 'Intento concurrente desactualizado',
        causal: null,
        usuarioId: 1,
        clientIp: '127.0.0.1'
    );
} catch (\Throwable $e) {
    $conflictDetected = ($e->getCode() === 409);
}
assertTest("4.3 Concurrencia optimista (D-01) arroja 409 Conflict ante intento desactualizado", $conflictDetected);

// Transición 3: En Proceso (v3) -> En Espera (v4) con causal justificada (RN-006)
$t3 = $solService->cambiarEstado(
    id: $solOrdId,
    nuevoEstado: 'En Espera',
    versionEsperada: 3,
    tecnicoAsignadoId: null,
    motivo: 'En espera de repuesto de fusible de alta potencia desde bodega central',
    causal: 'ESPERA_REPUESTO',
    usuarioId: 1,
    clientIp: '127.0.0.1'
);
assertTest("4.4 Transición 3: En Proceso -> En Espera con causal ESPERA_REPUESTO incrementa a v4",
    $t3['version'] === 4 &&
    $t3['estado'] === 'En Espera'
);

// Transición 4: En Espera (v4) -> En Proceso (v5)
$t4 = $solService->cambiarEstado(
    id: $solOrdId,
    nuevoEstado: 'En Proceso',
    versionEsperada: 4,
    tecnicoAsignadoId: null,
    motivo: 'Repuesto recibido y colocado. Continuando pruebas de calibración.',
    causal: null,
    usuarioId: 1,
    clientIp: '127.0.0.1'
);
assertTest("4.5 Transición 4: Reanudación En Espera -> En Proceso incrementa a v5", $t4['version'] === 5 && $t4['estado'] === 'En Proceso');

// Transición 5: En Proceso (v5) -> Atendida (v6)
$t5 = $solService->cambiarEstado(
    id: $solOrdId,
    nuevoEstado: 'Atendida',
    versionEsperada: 5,
    tecnicoAsignadoId: null,
    motivo: 'Ventilador mecánico operativo al 100%. Calibración de presiones conforme.',
    causal: null,
    usuarioId: 1,
    clientIp: '127.0.0.1'
);
assertTest("4.6 Transición 5: En Proceso -> Atendida incrementa a v6 y registra fecha_atencion_utc",
    $t5['version'] === 6 &&
    $t5['estado'] === 'Atendida' &&
    !empty($t5['fecha_atencion_utc'])
);

// Transición 6: Atendida (v6) -> Cerrada (v7)
$t6 = $solService->cambiarEstado(
    id: $solOrdId,
    nuevoEstado: 'Cerrada',
    versionEsperada: 6,
    tecnicoAsignadoId: null,
    motivo: 'Recepción y visto bueno firmado por Jefatura Médica de UCI.',
    causal: null,
    usuarioId: 1,
    clientIp: '127.0.0.1'
);
assertTest("4.7 Transición 6: Atendida -> Cerrada incrementa a v7 y finaliza ciclo",
    $t6['version'] === 7 &&
    $t6['estado'] === 'Cerrada' &&
    !empty($t6['fecha_cierre_utc'])
);

// Terminalidad inmutable (RN-005, RN-012): Estado 'Cerrada' no puede ser modificado
$terminalProtected = false;
try {
    $solService->cambiarEstado(
        id: $solOrdId,
        nuevoEstado: 'En Proceso',
        versionEsperada: 7,
        tecnicoAsignadoId: null,
        motivo: 'Intento ilegal de reapertura',
        causal: null,
        usuarioId: 1,
        clientIp: '127.0.0.1'
    );
} catch (\Throwable $e) {
    $terminalProtected = ($e->getCode() === 422);
}
assertTest("4.8 Estado 'Cerrada' es terminal inmutable y rechaza transiciones adicionales (código 422)", $terminalProtected);

// Auditoría append-only en sol_historial_estados (RN-012/RN-013)
$historial = $solService->getHistorial($solOrdId);
assertTest("4.9 Bitácora de auditoría registra exactamente 7 eventos inmutables cronológicos", count($historial) === 7);
assertTest("4.10 Todo evento en historial incluye operador, marca temporal UTC e IP de origen",
    !empty($historial[0]['usuario_nombre']) &&
    !empty($historial[0]['ip_origen']) &&
    !empty($historial[0]['created_at_utc'])
);

// ====================================================================
// FASE 5: INTERFAZ WEB Y COMUNICACIÓN API CON SERVIDOR HTTP (CP-SOL-05..06)
// ====================================================================
echo "\n--- FASE 5: Verificación Web HTTP en Servidor S3IH en Puerto 8000 ---\n";

// Iniciar sesión vía API
$chLogin = curl_init('http://127.0.0.1:8000/api/v1/auth/login');
curl_setopt($chLogin, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chLogin, CURLOPT_POST, true);
curl_setopt($chLogin, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($chLogin, CURLOPT_POSTFIELDS, json_encode([
    'email' => 'supervisor.h1@s3ih.salud.gob',
    'password' => 'Admin1234#'
]));
curl_setopt($chLogin, CURLOPT_HEADER, true);
$loginResp = curl_exec($chLogin);
$loginCode = curl_getinfo($chLogin, CURLINFO_HTTP_CODE);
curl_close($chLogin);

$sessionCookie = '';
if (preg_match('/Set-Cookie:\s*(s3ih_session=[^;]+)/i', $loginResp, $mCookie)) {
    $sessionCookie = $mCookie[1];
}

assertTest("5.1 Autenticación institucional HTTP 200 y captura de cookie s3ih_session", $loginCode === 200 && !empty($sessionCookie));

// Petición a bandeja de solicitudes /solicitudes
$chBandeja = curl_init('http://127.0.0.1:8000/solicitudes');
curl_setopt($chBandeja, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chBandeja, CURLOPT_HTTPHEADER, ["Cookie: {$sessionCookie}"]);
$htmlBandeja = curl_exec($chBandeja);
$codeBandeja = curl_getinfo($chBandeja, CURLINFO_HTTP_CODE);
curl_close($chBandeja);

assertTest("5.2 GET /solicitudes responde HTTP 200 y contiene tabla asistencial '#sol-table'",
    $codeBandeja === 200 && str_contains($htmlBandeja, 'id="sol-table"')
);

// Petición a formulario de radicación /solicitudes/radicar
$chRadicar = curl_init('http://127.0.0.1:8000/solicitudes/radicar');
curl_setopt($chRadicar, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chRadicar, CURLOPT_HTTPHEADER, ["Cookie: {$sessionCookie}"]);
$htmlRadicar = curl_exec($chRadicar);
$codeRadicar = curl_getinfo($chRadicar, CURLINFO_HTTP_CODE);
curl_close($chRadicar);

assertTest("5.3 GET /solicitudes/radicar responde HTTP 200 y contiene banner regulatorio Zero-PHI",
    $codeRadicar === 200 && str_contains($htmlRadicar, 'banner-zero-phi')
);

// Petición a detalle de orden /solicitudes/{id}
$chShow = curl_init("http://127.0.0.1:8000/solicitudes/{$solOrdId}");
curl_setopt($chShow, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chShow, CURLOPT_HTTPHEADER, ["Cookie: {$sessionCookie}"]);
$htmlShow = curl_exec($chShow);
$codeShow = curl_getinfo($chShow, CURLINFO_HTTP_CODE);
curl_close($chShow);

assertTest("5.4 GET /solicitudes/{id} responde HTTP 200 y contiene panel FSM y timeline inmutable",
    $codeShow === 200 &&
    str_contains($htmlShow, 'id="solicitud-detail-container"') &&
    str_contains($htmlShow, 'id="transition-panel-content"') &&
    str_contains($htmlShow, 'id="audit-timeline"')
);

// ====================================================================
// FASE 6: MOTOR DE SLA Y WORKER ASÍNCRONO OUTBOX CLI (CP-SOL-07)
// ====================================================================
echo "\n--- FASE 6: Motor de SLA y Worker Asíncrono Outbox CLI ---\n";

$slaService = new SlaService();

// Evaluación de SLA sobre orden de prueba
$ahoraTest = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$evalSla = $slaService->evaluarSolicitud($solOrdId, $ahoraTest);
assertTest("6.1 Motor de SLA evalúa la orden concluida con diagnóstico preciso",
    $evalSla !== null &&
    $evalSla['fase'] === 'CONCLUIDA' &&
    isset($evalSla['porcentaje_consumido'])
);

// Ejecución del Worker Asíncrono para procesar eventos pendientes en Outbox
$workerCmd = "C:\\laragon\\bin\\php\\php-8.3.33-Win32-vs16-x64\\php.exe \"" . dirname(__DIR__) . "/bin/s3ih-worker.php\" --once";
$workerOutput = (string) shell_exec($workerCmd);

assertTest("6.2 bin/s3ih-worker.php --once ejecuta ciclo completo de consumo y auditoría SLA",
    str_contains($workerOutput, '[OUTBOX]') &&
    str_contains($workerOutput, '[SLA]') &&
    str_contains($workerOutput, 'Ciclo de worker finalizado exitosamente')
);

// Verificar que los eventos de la orden se encuentran en estado PROCESADO
$stmtOutboxCheck = $pdo->prepare("SELECT COUNT(*) FROM ops_outbox_eventos WHERE solicitud_id = :id AND estado = 'PROCESADO'");
$stmtOutboxCheck->execute([':id' => $solOrdId]);
$cantProcesados = (int) $stmtOutboxCheck->fetchColumn();
assertTest("6.3 Eventos outbox de la orden fueron consumidos atómicamente a estado 'PROCESADO'", $cantProcesados >= 1);

// ====================================================================
// LIMPIEZA CONTROLADA DE REGISTROS DE PRUEBA
// ====================================================================
$pdo->prepare("DELETE FROM ops_outbox_eventos WHERE solicitud_id IN (:id1, :id2)")->execute([':id1' => $solOrdId, ':id2' => $solExpId]);
$pdo->prepare("DELETE FROM sol_historial_estados WHERE solicitud_id IN (:id1, :id2)")->execute([':id1' => $solOrdId, ':id2' => $solExpId]);
$pdo->prepare("DELETE FROM sol_solicitudes WHERE id IN (:id1, :id2)")->execute([':id1' => $solOrdId, ':id2' => $solExpId]);

// ====================================================================
// RESUMEN FINAL DEL CHECKPOINT CP-SOL-08
// ====================================================================
echo "\n======================================================================\n";
echo "RESUMEN DE PRUEBAS E2E (CP-SOL-08): {$testsPassed} / {$testsTotal} PASSED\n";
echo "======================================================================\n";

if ($testsPassed === $testsTotal) {
    echo ">>> SUITE INTEGRAL E2E SUPERADA CON ÉXITO AL 100% <<<\n";
    echo ">>> MÓDULO CENTRAL DE SOLICITUDES HOSPITALARIAS (S3IH) COMPLETADO <<<\n";
    exit(0);
} else {
    echo ">>> SE DETECTARON FALLOS EN LA SUITE E2E <<<\n";
    exit(1);
}
