<?php
declare(strict_types=1);

// Test automatizado de integración para CP-SOL-03: API de Radicación Dual: Estándar y Expedita de Soporte Vital
// Workflow 08: Módulo Central de Solicitudes Hospitalarias (S3IH)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Core\Request;
use App\Models\Solicitud;
use App\Repositories\SedeRepository;
use App\Repositories\ServicioRepository;
use App\Repositories\SolicitudRepository;
use App\Repositories\SolicitudTipoRepository;
use App\Services\CorrelativoService;
use App\Services\SolicitudService;
use App\Validators\SolicitudValidator;

echo "============================================================\n";
echo "PRUEBAS CP-SOL-03: API DE RADICACIÓN DUAL Y ZERO-PHI (S3IH)\n";
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

// Limpieza controlada previa para pruebas aisladas e independientes
$pdo->exec("DELETE FROM ops_outbox_eventos WHERE payload_json LIKE '%test-cp-sol-03%' OR payload_json LIKE '%TEST%'");
$pdo->exec("DELETE FROM sol_historial_estados WHERE solicitud_id IN (SELECT id FROM sol_solicitudes WHERE descripcion_falla LIKE '%TEST-CP-SOL-03%')");
$pdo->exec("DELETE FROM ops_outbox_eventos WHERE solicitud_id IN (SELECT id FROM sol_solicitudes WHERE descripcion_falla LIKE '%TEST-CP-SOL-03%')");
$pdo->exec("DELETE FROM sol_solicitudes WHERE descripcion_falla LIKE '%TEST-CP-SOL-03%'");

$validator = new SolicitudValidator();
$service = new SolicitudService();
$solRepo = new SolicitudRepository();

// ============================================================
// 1. FILTRO DE SEGURIDAD HOSPITALARIA ZERO-PHI (RNF-035)
// ============================================================
echo "--- 1. Filtro Estricto de Seguridad Zero-PHI (RNF-035) ---\n";

// 1.1 Rechazo de Cédula de Identidad / DNI
$phiCiRechazado = false;
try {
    $validator->validateZeroPhi("Monitor multiparamétrico falla en cama 2. Paciente CI: 4829102 LP", "descripcion_falla");
} catch (RuntimeException $e) {
    $phiCiRechazado = ($e->getCode() === 422);
}
assertTest("Zero-PHI bloquea reporte con Cédula de Identidad (código 422)", $phiCiRechazado);

// 1.2 Rechazo de Historia Clínica / Expediente
$phiHcRechazado = false;
try {
    $validator->validateZeroPhi("Bomba de infusión descalibrada, paciente HC: 94821", "descripcion_falla");
} catch (RuntimeException $e) {
    $phiHcRechazado = ($e->getCode() === 422);
}
assertTest("Zero-PHI bloquea reporte con Historia Clínica HC: (código 422)", $phiHcRechazado);

// 1.3 Rechazo de Nombres de Pacientes
$phiNombreRechazado = false;
try {
    $validator->validateZeroPhi("Ventilador no responde para paciente Juan Perez en box 1", "descripcion_falla");
} catch (RuntimeException $e) {
    $phiNombreRechazado = ($e->getCode() === 422);
}
assertTest("Zero-PHI bloquea reporte con nombre de paciente (código 422)", $phiNombreRechazado);

// 1.4 Rechazo de Diagnóstico Clínico
$phiDxRechazado = false;
try {
    $validator->validateZeroPhi("Oxímetro dañado, diagnóstico: neumonia bilateral aguda", "descripcion_falla");
} catch (RuntimeException $e) {
    $phiDxRechazado = ($e->getCode() === 422);
}
assertTest("Zero-PHI bloquea reporte con diagnóstico clínico (código 422)", $phiDxRechazado);

// 1.5 Aprobación de Descripción Técnica Válida
$phiPermitido = true;
try {
    $validator->validateZeroPhi("Alarma sonora continua E-04 de presión en ventilador neonatal Servo-i", "descripcion_falla");
    $validator->validateZeroPhi("Pabellón Quirúrgico Central - Mesa Quirófano 2", "ubicacion_especifica");
} catch (RuntimeException $e) {
    $phiPermitido = false;
}
assertTest("Zero-PHI aprueba descripción técnica de equipamiento biomédico e infraestructura", $phiPermitido);

// ============================================================
// 2. VALIDACIÓN DE CATÁLOGOS E INTEGRIDAD REFERENCIAL
// ============================================================
echo "\n--- 2. Validación de Catálogos e Integridad Referencial ---\n";

// 2.1 Rechazo de Sede inexistente
$errSede = false;
try {
    $service->create([
        'sede_id' => 9999,
        'servicio_solicitante_id' => 1,
        'servicio_resolutor_id' => 4,
        'tipo_solicitud_id' => 1,
        'ubicacion_especifica' => 'UCI Cama 3',
        'descripcion_falla' => 'TEST-CP-SOL-03 Falla de sensor biomédico',
        'prioridad_asistencial' => 'ALTA',
        'usuario_solicitante_id' => 1,
    ]);
} catch (RuntimeException $e) {
    $errSede = ($e->getCode() === 404);
}
assertTest("Service rechaza sede inexistente con código 404", $errSede);

// 2.2 Rechazo de Servicio Solicitante inexistente
$errSrvSol = false;
try {
    $service->create([
        'sede_id' => 1,
        'servicio_solicitante_id' => 9999,
        'servicio_resolutor_id' => 4,
        'tipo_solicitud_id' => 1,
        'ubicacion_especifica' => 'UCI Cama 3',
        'descripcion_falla' => 'TEST-CP-SOL-03 Falla de sensor biomédico',
        'prioridad_asistencial' => 'ALTA',
        'usuario_solicitante_id' => 1,
    ]);
} catch (RuntimeException $e) {
    $errSrvSol = ($e->getCode() === 404);
}
assertTest("Service rechaza servicio solicitante inexistente con código 404", $errSrvSol);

// 2.3 Rechazo de Tipo de Solicitud inexistente
$errTipo = false;
try {
    $service->create([
        'sede_id' => 1,
        'servicio_solicitante_id' => 1,
        'servicio_resolutor_id' => 4,
        'tipo_solicitud_id' => 9999,
        'ubicacion_especifica' => 'UCI Cama 3',
        'descripcion_falla' => 'TEST-CP-SOL-03 Falla de sensor biomédico',
        'prioridad_asistencial' => 'ALTA',
        'usuario_solicitante_id' => 1,
    ]);
} catch (RuntimeException $e) {
    $errTipo = ($e->getCode() === 404);
}
assertTest("Service rechaza tipo de solicitud inexistente con código 404", $errTipo);

// ============================================================
// 3. RADICACIÓN ORDINARIA (POST /api/v1/solicitudes)
// ============================================================
echo "\n--- 3. Radicación Ordinaria y Persistencia ACID ---\n";

$solEstOrd = $service->create([
    'sede_id' => 1,
    'servicio_solicitante_id' => 1, // UCI
    'servicio_resolutor_id' => 4,   // Biomédica
    'tipo_solicitud_id' => 1,       // BIO-VENT-FALLA
    'ubicacion_especifica' => 'UCI Neonatal Box 02',
    'prioridad_asistencial' => 'ALTA',
    'descripcion_falla' => 'TEST-CP-SOL-03 Cable de alimentación de monitor con falso contacto',
    'usuario_solicitante_id' => 1,
], null, '192.168.10.45');

assertTest("Radicación ordinaria retorna ID numérico generado", $solEstOrd['id'] > 0);
assertTest("Correlativo inmutable generado con formato H1-2026-XXXXXX", (bool) preg_match('/^H1-\d{4}-\d{6}$/', $solEstOrd['codigo_correlativo']));
assertTest("Estado inicial determinista es 'Registrada'", $solEstOrd['estado'] === 'Registrada');
assertTest("Bandera es_expedita es 0 en orden ordinaria", (int) $solEstOrd['es_expedita'] === 0);
assertTest("Prioridad asistencial asignada es 'ALTA'", $solEstOrd['prioridad_asistencial'] === 'ALTA');
assertTest("Relaciones institucionales resueltas (Sede Central y Servicios)", !empty($solEstOrd['sede_nombre']) && !empty($solEstOrd['servicio_solicitante_nombre']));

// 3.1 Verificación de bitácora sol_historial_estados
$stmtHist = $pdo->prepare("SELECT * FROM sol_historial_estados WHERE solicitud_id = :id ORDER BY id ASC");
$stmtHist->execute(['id' => $solEstOrd['id']]);
$historial = $stmtHist->fetchAll();
assertTest("Se registró exactamente 1 entrada en sol_historial_estados", count($historial) === 1);
assertTest("Historial registra transición Inicio -> Registrada", ($historial[0]['estado_anterior'] ?? '') === 'Inicio' && ($historial[0]['estado_nuevo'] ?? '') === 'Registrada');
assertTest("Historial almacena causal 'RADICACION_INICIAL' e IP capturada", ($historial[0]['causal_codigo'] ?? '') === 'RADICACION_INICIAL' && ($historial[0]['ip_origen'] ?? '') === '192.168.10.45');

// 3.2 Verificación de Transactional Outbox (ops_outbox_eventos)
$stmtOut = $pdo->prepare("SELECT * FROM ops_outbox_eventos WHERE solicitud_id = :id");
$stmtOut->execute(['id' => $solEstOrd['id']]);
$outbox = $stmtOut->fetch();
assertTest("Se insertó evento en ops_outbox_eventos para la solicitud", $outbox !== false);
assertTest("Tipo de evento es 'SOLICITUD_CREADA'", ($outbox['tipo_evento'] ?? '') === 'SOLICITUD_CREADA');
assertTest("Estado inicial de evento outbox es 'PENDIENTE'", ($outbox['estado'] ?? '') === 'PENDIENTE');

// ============================================================
// 4. RADICACIÓN EXPEDITA DE SOPORTE VITAL (POST /api/v1/solicitudes/expedita)
// ============================================================
echo "\n--- 4. Radicación Expedita de Soporte Vital (UCI / Quirófanos) ---\n";

$solExpedita = $service->createExpedita([
    'sede_id' => 1,
    'servicio_solicitante_id' => 2, // Quirófano Central
    'servicio_resolutor_id' => 4,   // Biomédica
    'tipo_solicitud_id' => 1,       // Fallo ventilador
    'ubicacion_especifica' => 'Quirófano Quirúrgico QX-01 Mesa Central',
    'descripcion_falla' => 'TEST-CP-SOL-03 Parada intempestiva de mezclador de gases medicinales durante procedimiento activo',
    'usuario_solicitante_id' => 1,
], null, '192.168.10.88');

assertTest("Radicación expedita genera solicitud exitosa", $solExpedita['id'] > 0);
assertTest("Bandera es_expedita forzada a 1", (int) $solExpedita['es_expedita'] === 1);
assertTest("Prioridad asistencial forzada deterministamente a 'VITAL'", $solExpedita['prioridad_asistencial'] === 'VITAL');

// Verificación del evento outbox expedito
$stmtOutExp = $pdo->prepare("SELECT * FROM ops_outbox_eventos WHERE solicitud_id = :id");
$stmtOutExp->execute(['id' => $solExpedita['id']]);
$outboxExp = $stmtOutExp->fetch();
assertTest("Tipo de evento en Outbox es 'SOLICITUD_EXPEDITA_CREADA'", ($outboxExp['tipo_evento'] ?? '') === 'SOLICITUD_EXPEDITA_CREADA');

// ============================================================
// 5. CONTROL DE IDEMPOTENCIA (Idempotency-Key)
// ============================================================
echo "\n--- 5. Control Estricto de Idempotencia (Idempotency-Key) ---\n";

$idempotencyKey = 'uuid-s3ih-test-expedita-vital-' . time();

// Primer envío con Idempotency-Key
$solIdem1 = $service->createExpedita([
    'sede_id' => 1,
    'ubicacion_especifica' => 'UCI Trauma Box 01',
    'descripcion_falla' => 'TEST-CP-SOL-03 Fallo en circuito de aspiración traqueal',
    'usuario_solicitante_id' => 1,
], $idempotencyKey, '10.0.0.1');

assertTest("Primera petición con Idempotency-Key crea nueva orden (idempotent = false)", ($solIdem1['idempotent'] ?? null) === false);
$correlativoPrimerEnvio = $solIdem1['codigo_correlativo'];

// Segundo envío idéntico con la MISMA Idempotency-Key (simula reintento de red de guardia clínica)
$solIdem2 = $service->createExpedita([
    'sede_id' => 1,
    'ubicacion_especifica' => 'UCI Trauma Box 01',
    'descripcion_falla' => 'TEST-CP-SOL-03 Fallo en circuito de aspiración traqueal',
    'usuario_solicitante_id' => 1,
], $idempotencyKey, '10.0.0.1');

assertTest("Segundo envío con misma Idempotency-Key devuelve orden existente (idempotent = true)", ($solIdem2['idempotent'] ?? null) === true);
assertTest("Mismo ID retornado en petición idempotente", $solIdem1['id'] === $solIdem2['id']);
assertTest("Mismo código correlativo inmutable retornado", $solIdem1['codigo_correlativo'] === $solIdem2['codigo_correlativo']);

// Verificar que en base de datos solo exista 1 registro para esta Idempotency-Key
$stmtIdemCount = $pdo->prepare("SELECT COUNT(*) FROM ops_outbox_eventos WHERE JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.idempotency_key')) = :key");
$stmtIdemCount->execute(['key' => $idempotencyKey]);
$totalEventosKey = (int) $stmtIdemCount->fetchColumn();
assertTest("Solo existe exactamente 1 evento en Outbox para la clave de idempotencia (cero duplicaciones)", $totalEventosKey === 1);

// ============================================================
// 6. CONSULTAS Y ENDPOINTS GET
// ============================================================
echo "\n--- 6. Consultas, Filtros y Detalle ---\n";

$listaTodas = $service->list();
assertTest("Listado general retorna array con solicitudes registradas", count($listaTodas) >= 3);

$listaExpeditas = $service->list(['es_expedita' => 1]);
assertTest("Filtro es_expedita=1 retorna solo solicitudes de soporte vital", count($listaExpeditas) >= 2);

$detalle = $service->get($solExpedita['id']);
assertTest("Consulta de detalle por ID recupera datos completos", $detalle['codigo_correlativo'] === $solExpedita['codigo_correlativo']);

$detallePorCorrelativo = $service->getByCorrelativo($solExpedita['codigo_correlativo']);
assertTest("Consulta de detalle por correlativo recupera registro exacto", $detallePorCorrelativo['id'] === $solExpedita['id']);

$errNotFound = false;
try {
    $service->get(999999);
} catch (RuntimeException $e) {
    $errNotFound = ($e->getCode() === 404);
}
assertTest("Consulta de ID inexistente devuelve 404", $errNotFound);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES CP-SOL-03: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
