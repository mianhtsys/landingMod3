<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * CP-SOL-07: Motor de Reloj de SLA Hospitalario y Monitoreo Preventivo.
 * Evalúa el consumo de tiempos de respuesta (MTTA) y resolución (MTTR) contra sla_politicas.
 * Dispara alertas preventivas al 75% y escalamientos jerárquicos al 100%.
 */
final class SlaService
{
    /**
     * Evalúa el estado de SLA de una solicitud individual.
     */
    public function evaluarSolicitud(int $solicitudId, ?DateTimeImmutable $ahora = null): ?array
    {
        $ahora = $ahora ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $sql = "
            SELECT 
                s.id,
                s.codigo_correlativo,
                s.sede_id,
                s.tipo_solicitud_id,
                s.prioridad_asistencial,
                s.estado,
                s.es_expedita,
                s.fecha_radicacion_utc,
                s.fecha_asignacion_utc,
                s.fecha_inicio_proceso_utc,
                s.fecha_atencion_utc,
                s.fecha_cierre_utc,
                p.tiempo_max_respuesta_mtta_min,
                p.tiempo_max_resolucion_mttr_min,
                p.calendario_codigo
            FROM sol_solicitudes s
            LEFT JOIN sla_politicas p 
                ON p.tipo_solicitud_id = s.tipo_solicitud_id 
               AND p.prioridad_asistencial = s.prioridad_asistencial
            WHERE s.id = :id
        ";

        $stmt = Database::conn()->prepare($sql);
        $stmt->execute([':id' => $solicitudId]);
        $sol = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$sol) {
            return null;
        }

        return $this->calcularSlaOrden($sol, $ahora);
    }

    /**
     * Evalúa todas las órdenes de trabajo activas en la red hospitalaria.
     * Retorna diagnóstico detallado y conteos consolidados.
     *
     * @return array{
     *   resumen: array{total: int, normal: int, preventiva_75: int, escalamiento_100: int, pausadas: int, concluidas: int},
     *   solicitudes: list<array>
     * }
     */
    public function evaluarTodasLasActivas(?DateTimeImmutable $ahora = null): array
    {
        $ahora = $ahora ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $sql = "
            SELECT 
                s.id,
                s.codigo_correlativo,
                s.sede_id,
                s.tipo_solicitud_id,
                s.prioridad_asistencial,
                s.estado,
                s.es_expedita,
                s.fecha_radicacion_utc,
                s.fecha_asignacion_utc,
                s.fecha_inicio_proceso_utc,
                s.fecha_atencion_utc,
                s.fecha_cierre_utc,
                p.tiempo_max_respuesta_mtta_min,
                p.tiempo_max_resolucion_mttr_min,
                p.calendario_codigo
            FROM sol_solicitudes s
            LEFT JOIN sla_politicas p 
                ON p.tipo_solicitud_id = s.tipo_solicitud_id 
               AND p.prioridad_asistencial = s.prioridad_asistencial
            WHERE s.estado NOT IN ('Cerrada', 'Rechazada', 'Cancelada')
            ORDER BY s.id ASC
        ";

        $stmt = Database::conn()->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $resumen = [
            'total' => count($rows),
            'normal' => 0,
            'preventiva_75' => 0,
            'escalamiento_100' => 0,
            'pausadas' => 0,
            'concluidas' => 0
        ];

        $resultados = [];
        foreach ($rows as $row) {
            $eval = $this->calcularSlaOrden($row, $ahora);
            $resultados[] = $eval;

            switch ($eval['nivel_alerta']) {
                case 'NORMAL':
                    $resumen['normal']++;
                    break;
                case 'ALERTA_PREVENTIVA_75':
                    $resumen['preventiva_75']++;
                    break;
                case 'ESCALAMIENTO_JERARQUICO_100':
                    $resumen['escalamiento_100']++;
                    break;
                case 'PAUSADO':
                    $resumen['pausadas']++;
                    break;
                case 'CONCLUIDO':
                    $resumen['concluidas']++;
                    break;
            }
        }

        return [
            'resumen' => $resumen,
            'solicitudes' => $resultados
        ];
    }

    /**
     * Calcula métricas y umbrales para una fila de datos hospitalarios.
     */
    private function calcularSlaOrden(array $sol, DateTimeImmutable $ahora): array
    {
        $estado = (string) $sol['estado'];
        $prioridad = (string) $sol['prioridad_asistencial'];

        // Valores por defecto de política si no estuviera explícita en sla_politicas
        $mttaDefecto = match ($prioridad) {
            'VITAL' => 15,
            'ALTA' => 30,
            'MEDIA' => 60,
            default => 120,
        };
        $mttrDefecto = match ($prioridad) {
            'VITAL' => 60,
            'ALTA' => 180,
            'MEDIA' => 360,
            default => 720,
        };

        $mttaMaxMin = (int) ($sol['tiempo_max_respuesta_mtta_min'] ?? $mttaDefecto);
        $mttrMaxMin = (int) ($sol['tiempo_max_resolucion_mttr_min'] ?? $mttrDefecto);

        $fechaRadicacion = new DateTimeImmutable((string) $sol['fecha_radicacion_utc'], new DateTimeZone('UTC'));

        // Determinación de la fase evaluada
        if ($estado === 'Registrada') {
            // Fase MTTA: Tiempo de respuesta / asignación
            $fase = 'MTTA';
            $tiempoLimiteMin = $mttaMaxMin;
            $segundosTranscurridos = max(0, $ahora->getTimestamp() - $fechaRadicacion->getTimestamp());
            $minutosTranscurridos = round($segundosTranscurridos / 60, 2);
        } elseif ($estado === 'En Espera') {
            // Estado con reloj de SLA pausado por causal externa justificada (RN-006)
            $fase = 'MTTR_PAUSADO';
            $tiempoLimiteMin = $mttrMaxMin;
            $segundosTranscurridos = max(0, $ahora->getTimestamp() - $fechaRadicacion->getTimestamp());
            $minutosTranscurridos = round($segundosTranscurridos / 60, 2);
        } elseif (in_array($estado, ['Atendida', 'Cerrada'], true)) {
            // Orden finalizada
            $fase = 'CONCLUIDA';
            $tiempoLimiteMin = $mttrMaxMin;
            $fechaFin = !empty($sol['fecha_atencion_utc'])
                ? new DateTimeImmutable((string) $sol['fecha_atencion_utc'], new DateTimeZone('UTC'))
                : $ahora;
            $segundosTranscurridos = max(0, $fechaFin->getTimestamp() - $fechaRadicacion->getTimestamp());
            $minutosTranscurridos = round($segundosTranscurridos / 60, 2);
        } else {
            // Asignada o En Proceso -> Fase MTTR: Tiempo de resolución
            $fase = 'MTTR';
            $tiempoLimiteMin = $mttrMaxMin;
            $segundosTranscurridos = max(0, $ahora->getTimestamp() - $fechaRadicacion->getTimestamp());
            $minutosTranscurridos = round($segundosTranscurridos / 60, 2);
        }

        $porcentaje = ($tiempoLimiteMin > 0)
            ? round(($minutosTranscurridos / $tiempoLimiteMin) * 100, 2)
            : 0.0;

        // Clasificación del umbral de alerta
        if ($fase === 'MTTR_PAUSADO') {
            $nivelAlerta = 'PAUSADO';
            $requierePreventiva = false;
            $requiereEscalamiento = false;
        } elseif ($fase === 'CONCLUIDA') {
            $nivelAlerta = 'CONCLUIDO';
            $requierePreventiva = false;
            $requiereEscalamiento = ($porcentaje >= 100.0);
        } elseif ($porcentaje >= 100.0) {
            $nivelAlerta = 'ESCALAMIENTO_JERARQUICO_100';
            $requierePreventiva = true;
            $requiereEscalamiento = true;
        } elseif ($porcentaje >= 75.0) {
            $nivelAlerta = 'ALERTA_PREVENTIVA_75';
            $requierePreventiva = true;
            $requiereEscalamiento = false;
        } else {
            $nivelAlerta = 'NORMAL';
            $requierePreventiva = false;
            $requiereEscalamiento = false;
        }

        return [
            'solicitud_id' => (int) $sol['id'],
            'codigo_correlativo' => (string) $sol['codigo_correlativo'],
            'estado' => $estado,
            'prioridad_asistencial' => $prioridad,
            'es_expedita' => (bool) $sol['es_expedita'],
            'fase' => $fase,
            'minutos_transcurridos' => $minutosTranscurridos,
            'tiempo_limite_min' => $tiempoLimiteMin,
            'porcentaje_consumido' => $porcentaje,
            'nivel_alerta' => $nivelAlerta,
            'requiere_alerta_preventiva' => $requierePreventiva,
            'requiere_escalamiento' => $requiereEscalamiento,
        ];
    }
}
