<?php
declare(strict_types=1);

// Test automatizado de integración para CP-SOL-06: Interfaz Web Técnica: Detalle de Orden y Transiciones
// Workflow 08: Módulo Central de Solicitudes Hospitalarias (S3IH)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Controllers\Web\SolicitudesController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Router;
use App\Services\SolicitudService;

echo "============================================================\n";
echo "PRUEBAS CP-SOL-06: INTERFAZ WEB TÉCNICA Y AUDIT TRAIL (S3IH)\n";
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

// ============================================================
// 1. VERIFICACIÓN DE VISTA Y CERO SQL (Zero-SQL in Views)
// ============================================================
echo "--- 1. Verificación de Vista y Cero SQL en Capa de Presentación ---\n";

$viewShowPath = dirname(__DIR__) . '/app/Views/solicitudes/show.php';
assertTest("Archivo de vista app/Views/solicitudes/show.php existe", is_file($viewShowPath));

$contentShow = file_get_contents($viewShowPath) ?: '';

$sqlPatterns = [
    '/\bSELECT\s+(?:\*|[\w`,]+)\s+FROM\b/i',
    '/\bINSERT\s+INTO\b/i',
    '/\bUPDATE\s+\w+\s+SET\b/i',
    '/\bDELETE\s+FROM\b/i',
    '/\bPDO\b/i',
    '/\bDatabase::/i'
];
$hasSqlInShow = false;
foreach ($sqlPatterns as $pattern) {
    if (preg_match($pattern, $contentShow)) {
        $hasSqlInShow = true;
        break;
    }
}
assertTest("Vista de detalle show.php tiene CERO SQL (arquitectura limpia y desacoplada)", !$hasSqlInShow);

// ============================================================
// 2. COMPONENTES DOM REQUERIDOS EN show.php
// ============================================================
echo "\n--- 2. Elementos DOM e Integración Modular en show.php ---\n";

assertTest("Contiene contenedor principal '#solicitud-detail-container'", str_contains($contentShow, 'id="solicitud-detail-container"'));
assertTest("Contiene contenedor de ficha técnica '#ficha-tecnica'", str_contains($contentShow, 'id="ficha-tecnica"'));
assertTest("Contiene panel de transiciones '#transition-panel-content'", str_contains($contentShow, 'id="transition-panel-content"'));
assertTest("Contiene bitácora inmutable '#audit-timeline'", str_contains($contentShow, 'id="audit-timeline"'));
assertTest("Contiene indicadores de carga '#detail-loading' y error '#detail-error'", str_contains($contentShow, 'id="detail-loading"') && str_contains($contentShow, 'id="detail-error"'));
assertTest("Contiene encabezado con título '#sol-correlativo-title' y badges '#sol-badges-header'", str_contains($contentShow, 'id="sol-correlativo-title"') && str_contains($contentShow, 'id="sol-badges-header"'));
assertTest("Importa módulo JavaScript '/assets/js/modules/solicitud-detalle.js'", str_contains($contentShow, 'src="/assets/js/modules/solicitud-detalle.js"'));
assertTest("Incluye enlace de retorno rápido a bandeja '/solicitudes'", str_contains($contentShow, 'href="/solicitudes"'));

// ============================================================
// 3. ARCHIVOS ESTÁTICOS: JAVASCRIPT Y CSS
// ============================================================
echo "\n--- 3. Módulos JavaScript y Estilos CSS ---\n";

$jsPath = dirname(__DIR__) . '/public/assets/js/modules/solicitud-detalle.js';
assertTest("Módulo cliente public/assets/js/modules/solicitud-detalle.js existe", is_file($jsPath));

$jsContent = file_get_contents($jsPath) ?: '';
assertTest("Módulo JS importa cliente API (getSolicitud, getHistorialSolicitud, transicionarSolicitud)",
    str_contains($jsContent, 'getSolicitud') &&
    str_contains($jsContent, 'getHistorialSolicitud') &&
    str_contains($jsContent, 'transicionarSolicitud')
);
assertTest("Módulo JS maneja conflicto de concurrencia optimista HTTP 409 (D-01)", str_contains($jsContent, '409'));
assertTest("Módulo JS soporta transiciones FSM (Registrada, Asignada, En Proceso, En Espera, Atendida, Cerrada)",
    str_contains($jsContent, 'Registrada') &&
    str_contains($jsContent, 'Asignada') &&
    str_contains($jsContent, 'En Proceso') &&
    str_contains($jsContent, 'En Espera') &&
    str_contains($jsContent, 'Atendida') &&
    str_contains($jsContent, 'Cerrada')
);
assertTest("Módulo JS valida causal externa justificada para 'En Espera' (RN-006)", str_contains($jsContent, 'trans-causal') && str_contains($jsContent, 'ESPERA_REPUESTO'));
assertTest("Módulo JS renderiza línea de tiempo inmutable (Audit Trail) con usuario, IP y UTC", str_contains($jsContent, 'timeline') && str_contains($jsContent, 'ip_origen') && str_contains($jsContent, 'created_at_utc'));

$cssPath = dirname(__DIR__) . '/public/assets/css/components.css';
$cssContent = file_get_contents($cssPath) ?: '';
assertTest("CSS define clases para Timeline (.timeline, .timeline-item, .timeline-dot)",
    str_contains($cssContent, '.timeline') &&
    str_contains($cssContent, '.timeline-item') &&
    str_contains($cssContent, '.timeline-dot')
);
assertTest("CSS define clases para Ficha Técnica (.detail-grid, .detail-card, .detail-row)",
    str_contains($cssContent, '.detail-grid') &&
    str_contains($cssContent, '.detail-card')
);
assertTest("CSS define clases para Panel de Transición (.transition-panel, .transition-box)",
    str_contains($cssContent, '.transition-panel') &&
    str_contains($cssContent, '.transition-box')
);

// ============================================================
// 4. CONTROLADOR WEB Y ENRUTAMIENTO
// ============================================================
echo "\n--- 4. Controlador Web y Enrutamiento Dinámico ---\n";

assertTest("SolicitudesController implementa método público 'show'", method_exists(SolicitudesController::class, 'show'));

$webRoutes = require dirname(__DIR__) . '/routes/web.php';
$hasShowRoute = false;
foreach ($webRoutes as $route) {
    if ($route[0] === 'GET' && $route[1] === '/solicitudes/{id}' && is_array($route[2]) && $route[2][0] === SolicitudesController::class && $route[2][1] === 'show') {
        $hasShowRoute = true;
        break;
    }
}
assertTest("Ruta web 'GET /solicitudes/{id}' registrada hacia SolicitudesController::show", $hasShowRoute);

// Test de coincidencia en Router
$router = new Router();
$extractedId = null;
$router->add('GET', '/solicitudes/{id}', function (Request $req) use (&$extractedId) {
    $extractedId = $req->param('id');
    throw new \RuntimeException('ROUTE_MATCHED');
});

// Configurar $_SERVER para que Request capture la ruta correcta
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/solicitudes/123';
$testRequest = new Request();
try {
    $router->dispatch($testRequest);
} catch (\RuntimeException $e) {
    // Captura esperada al ejecutar el handler
}
assertTest("Router extrae correctamente el parámetro dinámico 'id' = '123'", $extractedId === '123');

// ============================================================
// 5. FLUJO DE DATOS DEL DOMINIO PARA DETALLE Y HISTORIAL
// ============================================================
echo "\n--- 5. Servicios de Dominio: Consulta de Detalle e Historial ---\n";

$pdo = Database::conn();
// Crear una solicitud de prueba controlada para validar respuesta de detalle
$corrService = new \App\Services\CorrelativoService();
$testCorr = $corrService->generarSiguiente(1);

$stmtInsert = $pdo->prepare("
    INSERT INTO sol_solicitudes
        (codigo_correlativo, sede_id, servicio_solicitante_id, servicio_resolutor_id, tipo_solicitud_id,
         ubicacion_especifica, descripcion_falla, prioridad_asistencial, es_expedita,
         estado, version, usuario_solicitante_id, fecha_radicacion_utc, updated_at_utc)
    VALUES
        (:corr, 1, 1, 4, 1, 'Pabellón Quirúrgico Sala 2', 'Test CP-06 Ficha técnica', 'VITAL', 1,
         'Registrada', 1, 1, NOW(), NOW())
");
$stmtInsert->execute([':corr' => $testCorr]);
$solId = (int) $pdo->lastInsertId();

$service = new SolicitudService();
$solData = $service->get($solId);

assertTest("Servicio retorna detalle con campos obligatorios (id, codigo_correlativo, version)",
    $solData['id'] === $solId &&
    $solData['codigo_correlativo'] === $testCorr &&
    $solData['version'] === 1 &&
    $solData['estado'] === 'Registrada'
);
assertTest("Detalle incluye información extendida de relaciones (sede, servicios, tipo)",
    !empty($solData['sede_codigo']) &&
    !empty($solData['servicio_solicitante_nombre']) &&
    !empty($solData['servicio_resolutor_nombre']) &&
    !empty($solData['tipo_solicitud_nombre'])
);

// Transicionar la solicitud a Asignada para generar registro en historial
$service->cambiarEstado(
    id: $solId,
    nuevoEstado: 'Asignada',
    versionEsperada: 1,
    tecnicoAsignadoId: 1,
    motivo: 'Asignación técnica de prueba CP-06',
    causal: null,
    usuarioId: 1,
    clientIp: '127.0.0.1'
);

$historial = $service->getHistorial($solId);
assertTest("Historial retorna al menos un evento de auditoría append-only", count($historial) >= 1);
assertTest("Evento de historial contiene trazabilidad completa (estado_anterior, estado_nuevo, usuario_nombre, ip_origen)",
    isset($historial[0]['estado_anterior']) &&
    isset($historial[0]['estado_nuevo']) &&
    $historial[0]['estado_anterior'] === 'Registrada' &&
    $historial[0]['estado_nuevo'] === 'Asignada' &&
    !empty($historial[0]['usuario_nombre']) &&
    !empty($historial[0]['ip_origen'])
);

// ============================================================
// 6. PRUEBAS HTTP REALES CONTRA SERVIDOR S3IH (PUERTO 8000)
// ============================================================
echo "\n--- 6. Pruebas HTTP Reales contra Servidor S3IH en Puerto 8000 ---\n";

// A. Consulta GET /solicitudes/{id} sin sesión -> debe redirigir a /login (302)
$chUnauth = curl_init("http://127.0.0.1:8000/solicitudes/{$solId}");
curl_setopt($chUnauth, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chUnauth, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($chUnauth, CURLOPT_HEADER, true);
$respUnauth = curl_exec($chUnauth);
$httpCodeUnauth = curl_getinfo($chUnauth, CURLINFO_HTTP_CODE);
curl_close($chUnauth);

assertTest("GET /solicitudes/{id} sin sesión redirige a /login (HTTP 302)", $httpCodeUnauth === 302, "HTTP code recibido: {$httpCodeUnauth}");

// B. Iniciar sesión vía API para obtener cookie institucional s3ih_session
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
} elseif (preg_match('/Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $loginResp, $mCookie)) {
    $sessionCookie = $mCookie[1];
}

assertTest("Login institucional exitoso (HTTP 200) y cookie s3ih_session obtenida", $loginCode === 200 && !empty($sessionCookie), "Code: {$loginCode}, Cookie: {$sessionCookie}");

// C. Consulta GET /solicitudes/{id} con sesión válida -> 200 OK con HTML completo
$chAuth = curl_init("http://127.0.0.1:8000/solicitudes/{$solId}");
curl_setopt($chAuth, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chAuth, CURLOPT_HTTPHEADER, ["Cookie: {$sessionCookie}"]);
$htmlResp = curl_exec($chAuth);
$httpCodeAuth = curl_getinfo($chAuth, CURLINFO_HTTP_CODE);
curl_close($chAuth);

assertTest("GET /solicitudes/{id} con sesión autenticada responde HTTP 200", $httpCodeAuth === 200, "HTTP code: {$httpCodeAuth}");
assertTest("Respuesta HTML contiene contenedor 'solicitud-detail-container'", str_contains($htmlResp, 'id="solicitud-detail-container"'));
assertTest("Respuesta HTML contiene panel 'transition-panel-content'", str_contains($htmlResp, 'id="transition-panel-content"'));
assertTest("Respuesta HTML contiene contenedor 'audit-timeline'", str_contains($htmlResp, 'id="audit-timeline"'));
assertTest("Respuesta HTML incluye script de módulo 'solicitud-detalle.js'", str_contains($htmlResp, 'solicitud-detalle.js'));

// D. Consulta API GET /api/v1/solicitudes/{id} responde 200 con JSON
$chApi = curl_init("http://127.0.0.1:8000/api/v1/solicitudes/{$solId}");
curl_setopt($chApi, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chApi, CURLOPT_HTTPHEADER, ["Cookie: {$sessionCookie}"]);
$apiResp = curl_exec($chApi);
$apiCode = curl_getinfo($chApi, CURLINFO_HTTP_CODE);
curl_close($chApi);

$apiJson = json_decode($apiResp, true);
assertTest("GET /api/v1/solicitudes/{id} responde HTTP 200 con JSON exitoso",
    $apiCode === 200 && isset($apiJson['success']) && $apiJson['success'] === true,
    "Code: {$apiCode}"
);

// E. Consulta API GET /api/v1/solicitudes/{id}/historial responde 200 con JSON
$chHist = curl_init("http://127.0.0.1:8000/api/v1/solicitudes/{$solId}/historial");
curl_setopt($chHist, CURLOPT_RETURNTRANSFER, true);
curl_setopt($chHist, CURLOPT_HTTPHEADER, ["Cookie: {$sessionCookie}"]);
$histResp = curl_exec($chHist);
$histCode = curl_getinfo($chHist, CURLINFO_HTTP_CODE);
curl_close($chHist);

$histJson = json_decode($histResp, true);
assertTest("GET /api/v1/solicitudes/{id}/historial responde HTTP 200 con lista de eventos",
    $histCode === 200 && isset($histJson['success']) && $histJson['success'] === true && is_array($histJson['data']),
    "Code: {$histCode}"
);

// Limpieza controlada de la solicitud de prueba al terminar
$pdo->prepare("DELETE FROM ops_outbox_eventos WHERE solicitud_id = :id")->execute([':id' => $solId]);
$pdo->prepare("DELETE FROM sol_historial_estados WHERE solicitud_id = :id")->execute([':id' => $solId]);
$pdo->prepare("DELETE FROM sol_solicitudes WHERE id = :id")->execute([':id' => $solId]);

// ============================================================
// RESUMEN FINAL
// ============================================================
echo "\n============================================================\n";
echo "RESUMEN DE PRUEBAS CP-SOL-06: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    echo ">>> TODOS LOS TESTS PASARON EXITOSAMENTE. CP-SOL-06 VALIDADO AL 100% <<<\n";
    exit(0);
} else {
    echo ">>> SE DETECTARON FALLOS EN LA SUITE DE PRUEBAS <<<\n";
    exit(1);
}
