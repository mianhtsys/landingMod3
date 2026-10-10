#!/usr/bin/env php
<?php
declare(strict_types=1);

// CP-SOL-07: Worker Asíncrono de Procesamiento de Outbox y Monitoreo de Reloj de SLA (S3IH)
// Ejecución CLI para cron jobs, tareas programadas o ejecución continua en background.

require_once dirname(__DIR__) . '/app/Core/bootstrap.php';

use App\Services\OutboxWorkerService;
use App\Services\SlaService;

$options = getopt('', [
    'once',
    'outbox-only',
    'sla-only',
    'limit:',
    'quiet',
    'help'
]);

if (isset($options['help'])) {
    echo "Uso: php bin/s3ih-worker.php [opciones]\n";
    echo "Opciones:\n";
    echo "  --once         Ejecuta una pasada de procesamiento y finaliza (modo batch/cron)\n";
    echo "  --limit=N      Límite de eventos de Outbox por ciclo (defecto: 25)\n";
    echo "  --outbox-only  Ejecuta únicamente el consumo de Transactional Outbox\n";
    echo "  --sla-only     Ejecuta únicamente la auditoría del reloj de SLA\n";
    echo "  --quiet        Modo silencioso (reduce mensajes en stdout)\n";
    echo "  --help         Muestra esta ayuda\n";
    exit(0);
}

$isQuiet = isset($options['quiet']);
$runOutbox = !isset($options['sla-only']);
$runSla = !isset($options['outbox-only']);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 25;

if (!$isQuiet) {
    echo "============================================================\n";
    echo "S3IH WORKER: TRANSACTIONAL OUTBOX & MOTOR DE RELOJ SLA\n";
    echo "Fecha/Hora UTC: " . gmdate('Y-m-d H:i:s') . "\n";
    echo "============================================================\n";
}

$outboxService = new OutboxWorkerService();
$slaService = new SlaService();

// 1. Procesamiento de Outbox
if ($runOutbox) {
    if (!$isQuiet) {
        echo "\n[OUTBOX] Procesando eventos pendientes (FOR UPDATE SKIP LOCKED)...\n";
    }

    try {
        $resOutbox = $outboxService->procesarLote($limit);
        if (!$isQuiet) {
            echo "  Eventos encontrados: {$resOutbox['total_encontrados']}\n";
            echo "  Eventos procesados:  {$resOutbox['procesados']}\n";
            echo "  Eventos fallidos:    {$resOutbox['fallidos']}\n";

            foreach ($resOutbox['eventos'] as $ev) {
                echo "    -> [ID {$ev['id']}] {$ev['tipo_evento']} (Sol #{$ev['solicitud_id']}) => {$ev['estado']}\n";
            }
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[ERROR OUTBOX] " . $e->getMessage() . "\n");
    }
}

// 2. Monitoreo y Diagnóstico de SLA
if ($runSla) {
    if (!$isQuiet) {
        echo "\n[SLA] Auditando reloj de SLA en órdenes activas de la red...\n";
    }

    try {
        $resSla = $slaService->evaluarTodasLasActivas();
        $r = $resSla['resumen'];

        if (!$isQuiet) {
            echo "  Total órdenes activas:       {$r['total']}\n";
            echo "  Dentro de SLA (Normal):      {$r['normal']}\n";
            echo "  Alerta Preventiva (>= 75%):  {$r['preventiva_75']}\n";
            echo "  Escalamiento (>= 100%):      {$r['escalamiento_100']}\n";
            echo "  Pausadas en espera (RN-006): {$r['pausadas']}\n";

            if ($r['preventiva_75'] > 0 || $r['escalamiento_100'] > 0) {
                echo "\n  DETALLE DE SOLICITUDES EN RIESGO O INCUMPLIDAS:\n";
                foreach ($resSla['solicitudes'] as $sol) {
                    if ($sol['nivel_alerta'] === 'ESCALAMIENTO_JERARQUICO_100') {
                        echo "    🚨 [ESCALAMIENTO 100%] {$sol['codigo_correlativo']} ({$sol['prioridad_asistencial']}) - Consumo: {$sol['porcentaje_consumido']}% ({$sol['minutos_transcurridos']}/{$sol['tiempo_limite_min']} min en {$sol['fase']})\n";
                    } elseif ($sol['nivel_alerta'] === 'ALERTA_PREVENTIVA_75') {
                        echo "    ⚠️ [ALERTA 75%]        {$sol['codigo_correlativo']} ({$sol['prioridad_asistencial']}) - Consumo: {$sol['porcentaje_consumido']}% ({$sol['minutos_transcurridos']}/{$sol['tiempo_limite_min']} min en {$sol['fase']})\n";
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "[ERROR SLA] " . $e->getMessage() . "\n");
    }
}

if (!$isQuiet) {
    echo "\nCiclo de worker finalizado exitosamente.\n";
}
exit(0);
