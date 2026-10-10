<?php
declare(strict_types=1);

// Test automatizado de integración para CP-SOL-02: Modelo de Dominio y Generador de Correlativos Inmutables
// Workflow 08: Módulo Central de Solicitudes Hospitalarias (S3IH)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Core\Database;
use App\Models\Sede;
use App\Models\Servicio;
use App\Models\SolicitudTipo;
use App\Models\Solicitud;
use App\Repositories\SedeRepository;
use App\Repositories\ServicioRepository;
use App\Repositories\SolicitudRepository;
use App\Services\CorrelativoService;

echo "============================================================\n";
echo "PRUEBAS CP-SOL-02: MODELO DE DOMINIO Y GENERADOR DE CORRELATIVOS (S3IH)\n";
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

// Limpieza controlada de datos de prueba respetando restricciones FK ON DELETE RESTRICT
$pdo->exec("DELETE FROM ops_outbox_eventos WHERE solicitud_id IN (SELECT id FROM sol_solicitudes WHERE codigo_correlativo LIKE 'H%-2026-%' OR codigo_correlativo LIKE 'H%-2027-%')");
$pdo->exec("DELETE FROM sol_historial_estados WHERE solicitud_id IN (SELECT id FROM sol_solicitudes WHERE codigo_correlativo LIKE 'H%-2026-%' OR codigo_correlativo LIKE 'H%-2027-%')");
$pdo->exec("DELETE FROM sol_solicitudes WHERE codigo_correlativo LIKE 'H%-2026-%' OR codigo_correlativo LIKE 'H%-2027-%'");
$pdo->exec("DELETE FROM sol_correlativo_secuencia WHERE anio IN (2026, 2027)");

// ============================================================
// 1. MODELOS DE DOMINIO Y MAPEOS
// ============================================================
echo "--- 1. Modelos de Dominio y Mapeos Tipados ---\n";

$sedeRepo = new SedeRepository();
$sedesActivas = $sedeRepo->findAllActivas();
assertTest("SedeRepository recupera lista de sedes activas", count($sedesActivas) === 4);

$h1 = $sedeRepo->findByCodigo('H1');
assertTest("SedeRepository encuentra sede H1 por código institucional", $h1 !== null);
assertTest("Modelo Sede mapea id, nombre y es_central correctamente", $h1 instanceof Sede && $h1->esCentral === true);

$servicioRepo = new ServicioRepository();
$asistenciales = $servicioRepo->findBySede(1, 'ASISTENCIAL');
assertTest("ServicioRepository filtra servicios tipo ASISTENCIAL para H1", count($asistenciales) >= 3);

$resolutores = $servicioRepo->findBySede(1, 'RESOLUTOR');
assertTest("ServicioRepository filtra servicios tipo RESOLUTOR para H1", count($resolutores) >= 3);

// ============================================================
// 2. GENERADOR ATÓMICO DE CORRELATIVOS INMUTABLES (RN-001)
// ============================================================
echo "\n--- 2. Generador Atómico de Correlativos Inmutables (RN-001) ---\n";

$correlativoService = new CorrelativoService($sedeRepo);

// 2.1 Generación inicial para Sede 1 (H1) en año 2026
$c1 = $correlativoService->generarSiguiente(1, 2026);
assertTest("Primer correlativo H1 en 2026 es H1-2026-000001", $c1 === 'H1-2026-000001');

// 2.2 Formato estricto regex: H{sede}-{año}-{secuencial_6_digitos}
$regexOk = (bool) preg_match('/^H[1-4]-\d{4}-\d{6}$/', $c1);
assertTest("Correlativo cumple patrón estricto RN-001 (H{sede}-{año}-{secuencial})", $regexOk);

// 2.3 Consecutivo secuencial estricto para la misma sede y año
$c2 = $correlativoService->generarSiguiente(1, 2026);
assertTest("Segundo correlativo consecutivo H1 es H1-2026-000002", $c2 === 'H1-2026-000002');

$c3 = $correlativoService->generarSiguiente(1, 2026);
assertTest("Tercer correlativo consecutivo H1 es H1-2026-000003", $c3 === 'H1-2026-000003');

// 2.4 Aislamiento de secuencia por sede hospitalaria (Sede 2 empieza en 000001)
$c4 = $correlativoService->generarSiguiente(2, 2026);
assertTest("Correlativo para Sede H2 inicia en su propia secuencia (H2-2026-000001)", $c4 === 'H2-2026-000001');

// 2.5 Aislamiento de secuencia por cambio de año (Año 2027 reinicia secuencia para H1)
$c5 = $correlativoService->generarSiguiente(1, 2027);
assertTest("Correlativo para H1 en año 2027 reinicia en H1-2027-000001", $c5 === 'H1-2027-000001');

// 2.6 Validación de sede inexistente arroja 404
$errorSede = false;
try {
    $correlativoService->generarSiguiente(999, 2026);
} catch (RuntimeException $e) {
    $errorSede = ($e->getCode() === 404);
}
assertTest("Generador rechaza sede hospitalaria inexistente con código 404", $errorSede);

// ============================================================
// 3. PERSISTENCIA ATÓMICA EN SolicitudRepository
// ============================================================
echo "\n--- 3. Persistencia Atómica en SolicitudRepository ---\n";

$solicitudRepo = new SolicitudRepository();

// Inserción de una orden hospitalaria de prueba de soporte vital
$correlativoOrden = $correlativoService->generarSiguiente(1, 2026);

$solicitudId = $solicitudRepo->insert([
    'codigo_correlativo' => $correlativoOrden,
    'sede_id' => 1,
    'servicio_solicitante_id' => 1, // UCI
    'servicio_resolutor_id' => 4,   // Biomédica
    'tipo_solicitud_id' => 1,       // BIO-VENT-FALLA
    'ubicacion_especifica' => 'Cama UCI-04 Box Aislamiento',
    'prioridad_asistencial' => 'VITAL',
    'descripcion_falla' => 'Alarma de presión inspiratoria continua en ventilador mecánico neonatal.',
    'estado' => 'Registrada',
    'es_expedita' => 1,
    'usuario_solicitante_id' => 1,
]);

assertTest("SolicitudRepository inserta orden hospitalaria con ID numérico válido", $solicitudId > 0);

// Búsqueda por ID
$solRecuperada = $solicitudRepo->findById($solicitudId);
assertTest("SolicitudRepository recupera la orden por ID", $solRecuperada !== null);
assertTest("Correlativo inmutable coincide exactamente", ($solRecuperada?->codigoCorrelativo ?? '') === $correlativoOrden);
assertTest("Prioridad asistencial registrada como VITAL", ($solRecuperada?->prioridadAsistencial ?? '') === 'VITAL');
assertTest("Bandera es_expedita es verdadera (Soporte vital)", ($solRecuperada?->esExpedita ?? false) === true);
assertTest("Versión inicial de concurrencia optimista es 1", ($solRecuperada?->version ?? 0) === 1);
assertTest("Estado inicial determinista es 'Registrada'", ($solRecuperada?->estado ?? '') === 'Registrada');

// Búsqueda por código correlativo inmutable
$solPorCodigo = $solicitudRepo->findByCorrelativo($correlativoOrden);
assertTest("SolicitudRepository recupera la orden por código correlativo inmutable", $solPorCodigo !== null);
assertTest("existsCorrelativo retorna verdadero para código existente", $solicitudRepo->existsCorrelativo($correlativoOrden));
assertTest("existsCorrelativo retorna falso para código no registrado", !$solicitudRepo->existsCorrelativo('H1-2099-999999'));

echo "\n============================================================\n";
echo "RESULTADOS TOTALES: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
