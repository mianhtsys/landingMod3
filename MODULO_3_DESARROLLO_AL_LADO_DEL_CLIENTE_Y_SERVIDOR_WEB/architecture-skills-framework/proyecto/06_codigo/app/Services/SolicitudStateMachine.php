<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class SolicitudStateMachine
{
    /**
     * Matriz determinista de transiciones válidas según RN-005:
     * origen => [destinos_permitidos]
     */
    public const TRANSICIONES_PERMITIDAS = [
        'Registrada' => ['Asignada', 'Rechazada', 'Cancelada'],
        'Asignada' => ['En Proceso', 'Asignada', 'Cancelada'],
        'En Proceso' => ['En Espera', 'Atendida', 'Asignada'],
        'En Espera' => ['En Proceso'],
        'Atendida' => ['Cerrada'],
        'Cerrada' => [],   // Terminal inmutable (RN-005, RN-012)
        'Rechazada' => [], // Terminal inmutable (RN-005, RN-012)
        'Cancelada' => [], // Terminal inmutable (RN-005, RN-012)
    ];

    public const CAUSALES_ESPERA = [
        'ESPERA_REPUESTO',
        'EQUIPO_EN_GARANTIA',
        'ESPERA_LIBERACION_AREA',
        'AUTORIZACION_BIOMEDICA',
    ];

    /**
     * Determina si una transición de estado es legal.
     */
    public static function canTransition(string $estadoActual, string $nuevoEstado): bool
    {
        $permitidos = self::TRANSICIONES_PERMITIDAS[$estadoActual] ?? null;
        if ($permitidos === null) {
            return false;
        }
        return in_array($nuevoEstado, $permitidos, true);
    }

    /**
     * Valida la transición o lanza excepción con código 422.
     */
    public static function assertCanTransition(string $estadoActual, string $nuevoEstado): void
    {
        if (!array_key_exists($estadoActual, self::TRANSICIONES_PERMITIDAS)) {
            throw new RuntimeException("Estado actual '{$estadoActual}' desconocido en la máquina de estados", 422);
        }

        if (self::isTerminal($estadoActual)) {
            throw new RuntimeException(
                "La orden hospitalaria se encuentra en estado terminal inmutable '{$estadoActual}'. No se permiten modificaciones (RN-005/RN-012).",
                422
            );
        }

        if (!self::canTransition($estadoActual, $nuevoEstado)) {
            $permitidos = implode(', ', self::TRANSICIONES_PERMITIDAS[$estadoActual]);
            $mensajePermitidos = $permitidos !== '' ? $permitidos : 'ninguno (estado terminal)';
            throw new RuntimeException(
                "Transición de estado ilegal (RN-005): No es posible transicionar de '{$estadoActual}' a '{$nuevoEstado}'. Transiciones válidas: [{$mensajePermitidos}].",
                422
            );
        }
    }

    /**
     * Retorna los estados siguientes legalmente alcanzables.
     * @return list<string>
     */
    public static function getNextStates(string $estadoActual): array
    {
        return self::TRANSICIONES_PERMITIDAS[$estadoActual] ?? [];
    }

    /**
     * Verifica si un estado es final/terminal.
     */
    public static function isTerminal(string $estado): bool
    {
        return empty(self::TRANSICIONES_PERMITIDAS[$estado]);
    }
}
