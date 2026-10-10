<?php
declare(strict_types=1);

// Test automatizado de integración para CP-SOL-04: Máquina de Estados Finita y Concurrencia Optimista
// Workflow 08: Módulo Central de Solicitudes Hospitalarias (S3IH)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Repositories\SolicitudRepository;
use App\Services\SolicitudService;
use App\Services\SolicitudStateMachine;
use App\Validators\SolicitudValidator;

echo "============================================================\n";
echo "PRUEBAS CP-SOL-04: MÁQUINA DE ESTADOS Y CONCURRENCIA OPTIMISTA (S3IH)\n";
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

// Limpieza controlada de registros de prueba respetando integridad FK
$pdo->exec("DELETE FROM ops_outbox_eventos WHERE solicitud_id IN (SELECT id FROM sol_solicitudes WHERE descripcion_falla LIKE '%TEST-CP-SOL-04%')");
$pdo->exec("DELETE FROM sol_historial_estados WHERE solicitud_id IN (SELECT id FROM sol_solicitudes WHERE descripcion_falla LIKE '%TEST-CP-SOL-04%')");
$pdo->exec("DELETE FROM sol_solicitudes WHERE descripcion_falla LIKE '%TEST-CP-SOL-04%'");

$validator = new SolicitudValidator();
$service = new SolicitudService();
$solRepo = new SolicitudRepository();

// ============================================================
// 1. VERIFICACIÓN PURA DE LA FSM (SolicitudStateMachine - RN-005)
// ============================================================
echo "--- 1. Matriz de Transiciones de la FSM (RN-005) ---\n";

// 1.1 Transiciones legales
assertTest("FSM permite Registrada -> Asignada", SolicitudStateMachine::canTransition('Registrada', 'Asignada'));
assertTest("FSM permite Registrada -> Rechazada", SolicitudStateMachine::canTransition('Registrada', 'Rechazada'));
assertTest("FSM permite Registrada -> Cancelada", SolicitudStateMachine::canTransition('Registrada', 'Cancelada'));
assertTest("FSM permite Asignada -> En Proceso", SolicitudStateMachine::canTransition('Asignada', 'En Proceso'));
assertTest("FSM permite Asignada -> Asignada (reasignación)", SolicitudStateMachine::canTransition('Asignada', 'Asignada'));
assertTest("FSM permite En Proceso -> En Espera", SolicitudStateMachine::canTransition('En Proceso', 'En Espera'));
assertTest("FSM permite En Espera -> En Proceso", SolicitudStateMachine::canTransition('En Espera', 'En Proceso'));
assertTest("FSM permite En Proceso -> Atendida", SolicitudStateMachine::canTransition('En Proceso', 'Atendida'));
assertTest("FSM permite Atendida -> Cerrada", SolicitudStateMachine::canTransition('Atendida', 'Cerrada'));

// 1.2 Transiciones ilegales (saltos no permitidos)
assertTest("FSM rechaza salto ilegal Registrada -> Cerrada", !SolicitudStateMachine::canTransition('Registrada', 'Cerrada'));
assertTest("FSM rechaza salto ilegal Registrada -> En Proceso", !SolicitudStateMachine::canTransition('Registrada', 'En Proceso'));
assertTest("FSM rechaza salto ilegal Registrada -> Atendida", !SolicitudStateMachine::canTransition('Registrada', 'Atendida'));
assertTest("FSM rechaza salto ilegal Asignada -> Atendida", !SolicitudStateMachine::canTransition('Asignada', 'Atendida'));
assertTest("FSM rechaza salto ilegal En Espera -> Cerrada", !SolicitudStateMachine::canTransition('En Espera', 'Cerrada'));

// 1.3 Estados terminales inmutables (RN-005, RN-012)
assertTest("Estado 'Cerrada' es terminal inmutable", SolicitudStateMachine::isTerminal('Cerrada'));
assertTest("Estado 'Rechazada' es terminal inmutable", SolicitudStateMachine::isTerminal('Rechazada'));
assertTest("Estado 'Cancelada' es terminal inmutable", SolicitudStateMachine::isTerminal('Cancelada'));

$errTerminal = false;
try {
    SolicitudStateMachine::assertCanTransition('Cerrada', 'En Proceso');
} catch (RuntimeException $e) {
    $errTerminal = ($e->getCode() === 422);
}
assertTest("FSM arroja 422 al intentar modificar estado terminal 'Cerrada'", $errTerminal);

// ============================================================
// 2. CICLO DE VIDA COMPLETO CON CONCURRENCIA OPTIMISTA (D-01)
// ============================================================
echo "\n--- 2. Ciclo de Vida Completo y Control de Versiones (D-01) ---\n";

// Crear solicitud inicial (nace en 'Registrada', version = 1)
$orden = $service->create([
    'sede_id' => 1,
    'servicio_solicitante_id' => 1,
    'servicio_resolutor_id' => 4,
    'tipo_solicitud_id' => 1,
    'ubicacion_especifica' => 'UCI Neonatal Cama 05',
    'prioridad_asistencial' => 'ALTA',
    'descripcion_falla' => 'TEST-CP-SOL-04 Ciclo completo FSM: Monitor hemodinámico descalibrado',
    'usuario_solicitante_id' => 1,
], null, '10.20.1.100');

$solId = $orden['id'];
assertTest("Solicitud inicial creada en estado 'Registrada' y version 1", $orden['estado'] === 'Registrada' && (int)$orden['version'] === 1);

// Transición 1: Registrada -> Asignada (version 1 -> 2)
$t1 = $service->cambiarEstado(
    id: $solId,
    nuevoEstado: 'Asignada',
    versionEsperada: 1,
    tecnicoAsignadoId: 1,
    motivo: 'Asignación de guardia biomédica diurna',
    causal: 'ASIGNACION_TECNICA',
    usuarioId: 1,
    clientIp: '10.20.1.101'
);
assertTest("Transición 1: Registrada -> Asignada exitosa", $t1['estado'] === 'Asignada');
assertTest("Transición 1 incrementa version a 2", (int)$t1['version'] === 2);
assertTest("Transición 1 registra tecnico_asignado_id y fecha_asignacion_utc", (int)$t1['tecnico_asignado_id'] === 1 && !empty($t1['fecha_asignacion_utc']));

// Transición 2: Asignada -> En Proceso (version 2 -> 3)
$t2 = $service->cambiarEstado(
    id: $solId,
    nuevoEstado: 'En Proceso',
    versionEsperada: 2,
    tecnicoAsignadoId: 1,
    motivo: 'Técnico inicia protocolo de diagnóstico en sitio',
    causal: 'INICIO_LABORES',
    usuarioId: 1,
    clientIp: '10.20.1.102'
);
assertTest("Transición 2: Asignada -> En Proceso exitosa", $t2['estado'] === 'En Proceso');
assertTest("Transición 2 incrementa version a 3", (int)$t2['version'] === 3);
assertTest("Transición 2 estampa fecha_inicio_proceso_utc", !empty($t2['fecha_inicio_proceso_utc']));

// Transición 3: En Proceso -> En Espera (version 3 -> 4, suspensión de SLA - RN-006)
$t3 = $service->cambiarEstado(
    id: $solId,
    nuevoEstado: 'En Espera',
    versionEsperada: 3,
    tecnicoAsignadoId: 1,
    motivo: 'Falta de sensor de oximetría neonatal en stock local',
    causal: 'ESPERA_REPUESTO',
    usuarioId: 1,
    clientIp: '10.20.1.103'
);
assertTest("Transición 3: En Proceso -> En Espera exitosa (RN-006)", $t3['estado'] === 'En Espera');
assertTest("Transición 3 incrementa version a 4", (int)$t3['version'] === 4);

// Transición 4: En Espera -> En Proceso (version 4 -> 5, reanudación)
$t4 = $service->cambiarEstado(
    id: $solId,
    nuevoEstado: 'En Proceso',
    versionEsperada: 4,
    tecnicoAsignadoId: 1,
    motivo: 'Sensor entregado por Farmacia Central',
    causal: 'REANUDACION_LABORES',
    usuarioId: 1,
    clientIp: '10.20.1.104'
);
assertTest("Transición 4: En Espera -> En Proceso exitosa", $t4['estado'] === 'En Proceso');
assertTest("Transición 4 incrementa version a 5", (int)$t4['version'] === 5);

// Transición 5: En Proceso -> Atendida (version 5 -> 6, RN-007)
$t5 = $service->cambiarEstado(
    id: $solId,
    nuevoEstado: 'Atendida',
    versionEsperada: 5,
    tecnicoAsignadoId: 1,
    motivo: 'Sensor conectado, calibración superada al 100% en simulador',
    causal: 'ATENCION_CONCLUIDA',
    usuarioId: 1,
    clientIp: '10.20.1.105'
);
assertTest("Transición 5: En Proceso -> Atendida exitosa", $t5['estado'] === 'Atendida');
assertTest("Transición 5 incrementa version a 6", (int)$t5['version'] === 6);
assertTest("Transición 5 estampa fecha_atencion_utc", !empty($t5['fecha_atencion_utc']));

// Transición 6: Atendida -> Cerrada (version 6 -> 7, estado final)
$t6 = $service->cambiarEstado(
    id: $solId,
    nuevoEstado: 'Cerrada',
    versionEsperada: 6,
    tecnicoAsignadoId: 1,
    motivo: 'Conformidad de jefa de enfermería de UCI Neonatal',
    causal: 'CIERRE_CONFORME',
    usuarioId: 1,
    clientIp: '10.20.1.106'
);
assertTest("Transición 6: Atendida -> Cerrada exitosa", $t6['estado'] === 'Cerrada');
assertTest("Transición 6 incrementa version a 7", (int)$t6['version'] === 7);
assertTest("Transición 6 estampa fecha_cierre_utc", !empty($t6['fecha_cierre_utc']));

// ============================================================
// 3. DETECCIÓN DE CONFLICTOS CONCURRENTES (D-01 / OCC)
// ============================================================
echo "\n--- 3. Control de Concurrencia Optimista (D-01: Conflicto 409) ---\n";

// Crear otra solicitud para prueba de colisión
$ordenConflicto = $service->create([
    'sede_id' => 1,
    'servicio_solicitante_id' => 2,
    'servicio_resolutor_id' => 4,
    'tipo_solicitud_id' => 1,
    'ubicacion_especifica' => 'Quirófano 03',
    'prioridad_asistencial' => 'ALTA',
    'descripcion_falla' => 'TEST-CP-SOL-04 Prueba concurrencia optimista',
    'usuario_solicitante_id' => 1,
]);
$idConf = $ordenConflicto['id'];

// Usuario A actualiza con version 1 -> éxito, versión pasa a 2
$resA = $service->cambiarEstado(
    id: $idConf,
    nuevoEstado: 'Asignada',
    versionEsperada: 1,
    tecnicoAsignadoId: 1,
    motivo: 'Asignado por usuario A',
    causal: 'ASIGNACION_TECNICA',
    usuarioId: 1
);
assertTest("Usuario A ejecuta transición con versión correcta (version 1 -> 2)", (int)$resA['version'] === 2);

// Usuario B intenta actualizar concurrentemente usando la versión obsoleta (version 1)
$colisionDetectada = false;
try {
    $service->cambiarEstado(
        id: $idConf,
        nuevoEstado: 'Rechazada',
        versionEsperada: 1, // VERSIÓN DESACTUALIZADA
        tecnicoAsignadoId: null,
        motivo: 'Rechazo concurrente por usuario B',
        causal: 'RECHAZO_TECNICO',
        usuarioId: 1
    );
} catch (RuntimeException $e) {
    $colisionDetectada = ($e->getCode() === 409);
}
assertTest("Usuario B es bloqueado con código 409 Conflict ante colisión de versión (D-01)", $colisionDetectada);

// Verificar que la orden mantuvo el estado de Usuario A y no se corrompió
$ordenPostConflicto = $service->get($idConf);
assertTest("Estado de la orden permanece en 'Asignada' tras intento concurrente rechazado", $ordenPostConflicto['estado'] === 'Asignada');
assertTest("Versión permanece en 2 sin alteraciones", (int)$ordenPostConflicto['version'] === 2);

// ============================================================
// 4. BITÁCORA INMUTABLE Y AUDIT TRAIL (sol_historial_estados)
// ============================================================
echo "\n--- 4. Pista de Auditoría e Inmutabilidad (Audit Trail - RN-012/RN-013) ---\n";

$historial = $service->getHistorial($solId);
// 1 inicial (radicación) + 6 transiciones = exactamente 7 entradas
assertTest("Audit trail registra exactamente 7 transiciones cronológicas inmutables", count($historial) === 7);

$estadosEsperados = [
    ['Inicio', 'Registrada'],
    ['Registrada', 'Asignada'],
    ['Asignada', 'En Proceso'],
    ['En Proceso', 'En Espera'],
    ['En Espera', 'En Proceso'],
    ['En Proceso', 'Atendida'],
    ['Atendida', 'Cerrada'],
];

$cadenaCorrecta = true;
foreach ($estadosEsperados as $idx => [$ant, $nvo]) {
    if (($historial[$idx]['estado_anterior'] ?? '') !== $ant || ($historial[$idx]['estado_nuevo'] ?? '') !== $nvo) {
        $cadenaCorrecta = false;
        break;
    }
}
assertTest("Cadena secuencial de estados coincide al 100% con la historia de vida", $cadenaCorrecta);

// Verificación de no repudio: cada registro contiene usuario e IP
$noRepudioValido = true;
foreach ($historial as $h) {
    if (empty($h['usuario_nombre']) || empty($h['ip_origen']) || empty($h['created_at_utc'])) {
        $noRepudioValido = false;
        break;
    }
}
assertTest("Todos los registros de bitácora contienen no repudio legal (usuario, IP, UTC)", $noRepudioValido);

// ============================================================
// 5. PUBLICACIÓN DE EVENTOS EN TRANSACTIONAL OUTBOX
// ============================================================
echo "\n--- 5. Integración con Transactional Outbox (ops_outbox_eventos) ---\n";

$stmtOut = $pdo->prepare("SELECT COUNT(*) FROM ops_outbox_eventos WHERE solicitud_id = :id AND tipo_evento = 'SOLICITUD_ESTADO_CAMBIADO'");
$stmtOut->execute(['id' => $solId]);
$totalEventosCambio = (int) $stmtOut->fetchColumn();
assertTest("Se generaron exactamente 6 eventos 'SOLICITUD_ESTADO_CAMBIADO' en Outbox", $totalEventosCambio === 6);

// ============================================================
// 6. VALIDACIONES EN SolicitudValidator PARA TRANSICIONES
// ============================================================
echo "\n--- 6. Validaciones de Entrada para Transiciones ---\n";

// Falta nuevo_estado
$errVal1 = false;
try {
    $validator->validateTransitionBody(['version' => 1]);
} catch (RuntimeException $e) {
    $errVal1 = ($e->getCode() === 400);
}
assertTest("Validator exige campo 'nuevo_estado'", $errVal1);

// Falta version
$errVal2 = false;
try {
    $validator->validateTransitionBody(['nuevo_estado' => 'Asignada']);
} catch (RuntimeException $e) {
    $errVal2 = ($e->getCode() === 400);
}
assertTest("Validator exige campo 'version' para control optimista", $errVal2);

// Asignada sin técnico
$errVal3 = false;
try {
    $validator->validateTransitionBody(['nuevo_estado' => 'Asignada', 'version' => 1]);
} catch (RuntimeException $e) {
    $errVal3 = ($e->getCode() === 400);
}
assertTest("Validator exige 'tecnico_asignado_id' al transicionar a 'Asignada'", $errVal3);

// Rechazo sin motivo suficiente
$errVal4 = false;
try {
    $validator->validateTransitionBody(['nuevo_estado' => 'Rechazada', 'version' => 1, 'motivo' => 'no']);
} catch (RuntimeException $e) {
    $errVal4 = ($e->getCode() === 400);
}
assertTest("Validator exige justificación de al menos 5 caracteres al rechazar o pausar", $errVal4);

// Zero-PHI en motivo de transición
$errValPhi = false;
try {
    $validator->validateTransitionBody([
        'nuevo_estado' => 'Rechazada',
        'version' => 1,
        'motivo' => 'Rechazado porque paciente CI: 4981928 LP ya fue dado de alta',
    ]);
} catch (RuntimeException $e) {
    $errValPhi = ($e->getCode() === 422);
}
assertTest("Zero-PHI bloquea datos personales en motivo de transición (código 422)", $errValPhi);

echo "\n============================================================\n";
echo "RESULTADOS TOTALES CP-SOL-04: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
