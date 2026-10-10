<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\SedeRepository;
use RuntimeException;
use Throwable;

/**
 * RN-001: Generación atómica e inmutable de correlativo hospitalario
 * Formato: H{sede_id}-{año}-{secuencial_6_digitos} (ej. H1-2026-000001)
 */
final class CorrelativoService
{
    public function __construct(private readonly SedeRepository $sedes = new SedeRepository())
    {
    }

    /**
     * Reserva y retorna el siguiente código correlativo de manera atómica con bloqueo transaccional.
     * @param int $sedeId Identificador de sede (1..4)
     * @param int|null $anio Año en 4 dígitos (por defecto año actual UTC)
     * @return string Código correlativo inmutable (ej. H1-2026-000001)
     */
    public function generarSiguiente(int $sedeId, ?int $anio = null): string
    {
        if (!$this->sedes->exists($sedeId)) {
            throw new RuntimeException("Sede hospitalaria ID {$sedeId} no existe o no está activa", 404);
        }

        $anio = $anio ?? (int) gmdate('Y');
        $pdo = Database::conn();

        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // Bloqueo de fila FOR UPDATE para garantizar atomicidad concurrente estricta
            $q = $pdo->prepare('
                SELECT ultimo_secuencial 
                FROM sol_correlativo_secuencia 
                WHERE sede_id = :sede_id AND anio = :anio 
                FOR UPDATE
            ');
            $q->execute(['sede_id' => $sedeId, 'anio' => $anio]);
            $actual = $q->fetchColumn();

            if ($actual === false) {
                $siguiente = 1;
                $ins = $pdo->prepare('
                    INSERT INTO sol_correlativo_secuencia (sede_id, anio, ultimo_secuencial) 
                    VALUES (:sede_id, :anio, :secuencial)
                ');
                $ins->execute([
                    'sede_id' => $sedeId,
                    'anio' => $anio,
                    'secuencial' => $siguiente,
                ]);
            } else {
                $siguiente = (int) $actual + 1;
                $upd = $pdo->prepare('
                    UPDATE sol_correlativo_secuencia 
                    SET ultimo_secuencial = :nuevo 
                    WHERE sede_id = :sede_id AND anio = :anio
                ');
                $upd->execute([
                    'nuevo' => $siguiente,
                    'sede_id' => $sedeId,
                    'anio' => $anio,
                ]);
            }

            if ($ownTransaction) {
                $pdo->commit();
            }

            return sprintf('H%d-%d-%06d', $sedeId, $anio, $siguiente);
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('Error generando correlativo hospitalario atómico: ' . $e->getMessage(), 500, $e);
        }
    }
}
