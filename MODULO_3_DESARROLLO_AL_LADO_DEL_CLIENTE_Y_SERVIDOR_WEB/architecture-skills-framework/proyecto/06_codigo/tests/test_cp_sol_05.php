<?php
declare(strict_types=1);

// Test automatizado de integración para CP-SOL-05: Interfaz Web Asistencial: Bandeja y Radicación
// Workflow 08: Módulo Central de Solicitudes Hospitalarias (S3IH)

require_once __DIR__ . '/../app/Core/bootstrap.php';

use App\Controllers\Web\SolicitudesController;
use App\Core\Request;
use App\Core\Session;

echo "============================================================\n";
echo "PRUEBAS CP-SOL-05: INTERFAZ WEB ASISTENCIAL (S3IH)\n";
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
// 1. VERIFICACIÓN DE ARCHIVOS DE VISTA Y CERO SQL (Zero-SQL in Views)
// ============================================================
echo "--- 1. Verificación de Vistas y Cero SQL en Capa de Presentación ---\n";

$viewIndexPath = dirname(__DIR__) . '/app/Views/solicitudes/index.php';
$viewRadicarPath = dirname(__DIR__) . '/app/Views/solicitudes/radicar.php';

assertTest("Archivo de vista app/Views/solicitudes/index.php existe", is_file($viewIndexPath));
assertTest("Archivo de vista app/Views/solicitudes/radicar.php existe", is_file($viewRadicarPath));

$contentIndex = file_get_contents($viewIndexPath) ?: '';
$contentRadicar = file_get_contents($viewRadicarPath) ?: '';

$sqlPatterns = ['/\bSELECT\s+(?:\*|[\w`,]+)\s+FROM\b/i', '/\bINSERT\s+INTO\b/i', '/\bUPDATE\s+\w+\s+SET\b/i', '/\bDELETE\s+FROM\b/i', '/\bPDO\b/i', '/\bDatabase::/i'];
$hasSqlInIndex = false;
$hasSqlInRadicar = false;

foreach ($sqlPatterns as $pattern) {
    if (preg_match($pattern, $contentIndex)) $hasSqlInIndex = true;
    if (preg_match($pattern, $contentRadicar)) $hasSqlInRadicar = true;
}

assertTest("Vista bandeja index.php tiene CERO SQL (arquitectura limpia)", !$hasSqlInIndex);
assertTest("Vista radicación radicar.php tiene CERO SQL (arquitectura limpia)", !$hasSqlInRadicar);

// ============================================================
// 2. CUMPLIMIENTO REGULATORIO ZERO-PHI EN VISTA DE RADICACIÓN (RNF-035)
// ============================================================
echo "\n--- 2. Cumplimiento Regulatorio Zero-PHI (RNF-035) ---\n";

$tieneBannerZeroPhi = str_contains($contentRadicar, 'Zero-PHI') && str_contains($contentRadicar, 'banner-zero-phi');
assertTest("Vista radicar.php incluye banner regulatorio visible Zero-PHI (RNF-035)", $tieneBannerZeroPhi);

$tieneProhibicionExplicita = str_contains($contentRadicar, 'pacientes') && str_contains($contentRadicar, 'historia clínica');
assertTest("Vista radicar.php prohíbe explícitamente nombres de pacientes e historias clínicas", $tieneProhibicionExplicita);

// ============================================================
// 3. ESTRUCTURA Y ELEMENTOS DE LA BANDEJA ASISTENCIAL (index.php)
// ============================================================
echo "\n--- 3. Elementos de la Bandeja Asistencial ---\n";

assertTest("Bandeja contiene tabla con id='sol-table'", str_contains($contentIndex, 'id="sol-table"'));
assertTest("Bandeja contiene cuerpo con id='sol-tbody'", str_contains($contentIndex, 'id="sol-tbody"'));
assertTest("Bandeja incluye selector de filtro por Sede hospitalaria", str_contains($contentIndex, 'id="filter-sede"'));
assertTest("Bandeja incluye selector de filtro por Estado FSM", str_contains($contentIndex, 'id="filter-estado"'));
assertTest("Bandeja incluye selector de filtro por Prioridad SLA", str_contains($contentIndex, 'id="filter-prioridad"'));
assertTest("Bandeja incluye filtro reactivo de Soporte Vital (filter-expedita)", str_contains($contentIndex, 'id="filter-expedita"'));
assertTest("Bandeja incluye botón de navegación para radicar nueva solicitud", str_contains($contentIndex, 'href="/solicitudes/radicar"'));
assertTest("Bandeja carga módulo JS /assets/js/modules/solicitudes.js", str_contains($contentIndex, 'solicitudes.js'));

// ============================================================
// 4. ELEMENTOS DEL FORMULARIO DE RADICACIÓN DUAL (radicar.php)
// ============================================================
echo "\n--- 4. Elementos del Formulario de Radicación Dual ---\n";

assertTest("Formulario incluye selector de Modo Dual Estándar vs Expedita", str_contains($contentRadicar, 'btn-modo-estandar') && str_contains($contentRadicar, 'btn-modo-expedito'));
assertTest("Formulario incluye selector de sede hospitalaria (sede_id)", str_contains($contentRadicar, 'id="sede-id"'));
assertTest("Formulario incluye selector de servicio solicitante", str_contains($contentRadicar, 'id="servicio-solicitante-id"'));
assertTest("Formulario incluye selector de servicio resolutor", str_contains($contentRadicar, 'id="servicio-resolutor-id"'));
assertTest("Formulario incluye selector de tipo de solicitud", str_contains($contentRadicar, 'id="tipo-solicitud-id"'));
assertTest("Formulario incluye campo de ubicación específica", str_contains($contentRadicar, 'id="ubicacion-especifica"'));
assertTest("Formulario incluye campo de descripción técnica", str_contains($contentRadicar, 'id="descripcion-falla"'));
assertTest("Formulario carga módulo JS /assets/js/modules/solicitudes-radicar.js", str_contains($contentRadicar, 'solicitudes-radicar.js'));

// ============================================================
// 5. CONTROLADOR WEB Y RUTEO (routes/web.php)
// ============================================================
echo "\n--- 5. Controlador Web y Ruteo en routes/web.php ---\n";

$webRoutes = require dirname(__DIR__) . '/routes/web.php';
$hasRouteIndex = false;
$hasRouteRadicar = false;

foreach ($webRoutes as [$method, $uri, $handler]) {
    if ($method === 'GET' && $uri === '/solicitudes') $hasRouteIndex = true;
    if ($method === 'GET' && $uri === '/solicitudes/radicar') $hasRouteRadicar = true;
}

assertTest("Ruta GET /solicitudes registrada en routes/web.php", $hasRouteIndex);
assertTest("Ruta GET /solicitudes/radicar registrada en routes/web.php", $hasRouteRadicar);

// Verificación de protección de sesión: sin cookie redirige a /login
$webCtrl = new SolicitudesController();

// Limpiar sesión para verificar redirección
unset($_COOKIE[Session::name()]);
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

$ref = new ReflectionClass(SolicitudesController::class);
$methodHasSession = $ref->getMethod('hasSession');
$methodHasSession->setAccessible(true);
$sinSesion = $methodHasSession->invoke($webCtrl);
assertTest("SolicitudesController detecta ausencia de sesión para redirección a /login", $sinSesion === false);

// Simular sesión iniciada
@Session::start();
$_COOKIE[Session::name()] = session_id() ?: 'cli-test-session';
$_SESSION['user_id'] = 1;
$_SESSION['nombre'] = 'Oficial de TI H1';

$conSesion = $methodHasSession->invoke($webCtrl);
assertTest("SolicitudesController valida correctamente sesión hospitalaria activa", $conSesion === true);

// ============================================================
// 6. ASSETS JAVASCRIPT Y API CLIENT (solicitudes-api.js)
// ============================================================
echo "\n--- 6. Assets JavaScript y Módulos de Frontend ---\n";

$apiClientPath = dirname(__DIR__) . '/public/assets/js/api/solicitudes-api.js';
$modSolPath = dirname(__DIR__) . '/public/assets/js/modules/solicitudes.js';
$modRadPath = dirname(__DIR__) . '/public/assets/js/modules/solicitudes-radicar.js';

assertTest("Cliente API public/assets/js/api/solicitudes-api.js existe", is_file($apiClientPath));
assertTest("Módulo public/assets/js/modules/solicitudes.js existe", is_file($modSolPath));
assertTest("Módulo public/assets/js/modules/solicitudes-radicar.js existe", is_file($modRadPath));

$contentApiClient = file_get_contents($apiClientPath) ?: '';
assertTest("solicitudes-api.js exporta listSolicitudes", str_contains($contentApiClient, 'export const listSolicitudes'));
assertTest("solicitudes-api.js exporta createSolicitud", str_contains($contentApiClient, 'export const createSolicitud'));
assertTest("solicitudes-api.js exporta createSolicitudExpedita", str_contains($contentApiClient, 'export const createSolicitudExpedita'));
assertTest("solicitudes-api.js soporta cabecera Idempotency-Key", str_contains($contentApiClient, 'Idempotency-Key'));

$contentModRad = file_get_contents($modRadPath) ?: '';
assertTest("solicitudes-radicar.js genera Idempotency-Key UUID", str_contains($contentModRad, 'generarUuid'));
assertTest("solicitudes-radicar.js implementa checkClientZeroPhi para validación preventiva", str_contains($contentModRad, 'checkClientZeroPhi'));

// ============================================================
// 7. INTEGRACIÓN DE BARRA LATERAL (sidebar.php)
// ============================================================
echo "\n--- 7. Integración en Navegación Lateral (sidebar.php) ---\n";

$sidebarPath = dirname(__DIR__) . '/app/Views/layouts/sidebar.php';
$sidebarContent = file_get_contents($sidebarPath) ?: '';

assertTest("sidebar.php incluye enlace a /solicitudes", str_contains($sidebarContent, "'/solicitudes'"));
assertTest("sidebar.php incluye enlace a /solicitudes/radicar", str_contains($sidebarContent, "'/solicitudes/radicar'"));

echo "\n============================================================\n";
echo "RESULTADOS TOTALES CP-SOL-05: {$testsPassed} / {$testsTotal} PASSED\n";
echo "============================================================\n";

if ($testsPassed === $testsTotal) {
    exit(0);
} else {
    exit(1);
}
