<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * CP-SOL-07: Worker Asíncrono para el patrón Transactional Outbox (ops_outbox_eventos).
 * Consume eventos pendientes garantizando seguridad ante concurrencia con FOR UPDATE SKIP LOCKED.
 */
final class OutboxWorkerService
{
    public function __construct(
        private readonly int $maxRetries = 3
    ) {
    }

    /**
     * Procesa un lote de eventos pendientes de ops_outbox_eventos.
     * Utiliza bloqueo pesimista SKIP LOCKED para permitir múltiples workers simultáneos sin contención.
     *
     * @param int $limit Cantidad máxima de eventos por ciclo
     * @return array{total_encontrados: int, procesados: int, fallidos: int, eventos: list<array>}
     */
    public function procesarLote(int $limit = 25): array
    {
        $pdo = Database::conn();
        $pdo->beginTransaction();

        $summary = [
            'total_encontrados' => 0,
            'procesados' => 0,
            'fallidos' => 0,
            'eventos' => []
        ];

        try {
            // Consulta atómica con FOR UPDATE SKIP LOCKED (MySQL 8.0+ / InnoDB)
            $stmt = $pdo->prepare("
                SELECT id, solicitud_id, tipo_evento, payload_json, reintentos
                FROM ops_outbox_eventos
                WHERE estado = 'PENDIENTE'
                ORDER BY id ASC
                LIMIT :limit
                FOR UPDATE SKIP LOCKED
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $eventos = $stmt->fetchAll();

            $summary['total_encontrados'] = count($eventos);

            $stmtUpdateOk = $pdo->prepare("
                UPDATE ops_outbox_eventos
                SET estado = 'PROCESADO',
                    procesado_at_utc = UTC_TIMESTAMP(),
                    error_mensaje = NULL
                WHERE id = :id
            ");

            $stmtUpdateFail = $pdo->prepare("
                UPDATE ops_outbox_eventos
                SET reintentos = reintentos + 1,
                    estado = CASE WHEN reintentos + 1 >= :max_retries THEN 'FALLIDO' ELSE 'PENDIENTE' END,
                    error_mensaje = :error
                WHERE id = :id
            ");

            foreach ($eventos as $row) {
                $id = (int) $row['id'];
                $tipo = (string) $row['tipo_evento'];
                $solId = (int) $row['solicitud_id'];
                $reintentos = (int) $row['reintentos'];

                try {
                    $payload = json_decode((string) $row['payload_json'], true) ?: [];

                    // Despacho de la lógica de negocio asociada al evento
                    $this->despacharEvento($tipo, $solId, $payload);

                    // Marcar como PROCESADO
                    $stmtUpdateOk->execute([':id' => $id]);
                    $summary['procesados']++;

                    $summary['eventos'][] = [
                        'id' => $id,
                        'solicitud_id' => $solId,
                        'tipo_evento' => $tipo,
                        'estado' => 'PROCESADO'
                    ];
                } catch (Throwable $e) {
                    $errText = mb_substr($e->getMessage(), 0, 500);
                    $stmtUpdateFail->execute([
                        ':id' => $id,
                        ':max_retries' => $this->maxRetries,
                        ':error' => $errText
                    ]);
                    $summary['fallidos']++;

                    $summary['eventos'][] = [
                        'id' => $id,
                        'solicitud_id' => $solId,
                        'tipo_evento' => $tipo,
                        'estado' => ($reintentos + 1 >= $this->maxRetries) ? 'FALLIDO' : 'REINTENTO_PENDIENTE',
                        'error' => $errText
                    ];
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $summary;
    }

    /**
     * Lógica de despacho de eventos asíncronos.
     * En este entorno institucional, ejecuta notificaciones a guardias y auditoría de eventos.
     */
    private function despacharEvento(string $tipo, int $solicitudId, array $payload): void
    {
        switch ($tipo) {
            case 'SOLICITUD_EXPEDITA_CREADA':
                // Notificación inmediata de alta prioridad (Soporte Vital Quirófano/UCI)
                // En producción: webhook MQTT/SMS a guardia activa. Aquí: validación e inspección del evento.
                if (empty($payload['codigo_correlativo'])) {
                    throw new \RuntimeException('Payload de solicitud expedita incompleto: falta correlativo');
                }
                break;

            case 'SOLICITUD_CREADA':
                // Orden estándar radicada en cola técnica
                if (empty($payload['codigo_correlativo'])) {
                    throw new \RuntimeException('Payload de solicitud incompleto: falta correlativo');
                }
                break;

            case 'SOLICITUD_ESTADO_CAMBIADO':
                // Transición FSM (Registrada -> Asignada, etc.)
                if (empty($payload['estado_nuevo'])) {
                    throw new \RuntimeException('Payload de cambio de estado incompleto: falta estado_nuevo');
                }
                break;

            default:
                // Evento reconocido de forma genérica
                break;
        }
    }
}
