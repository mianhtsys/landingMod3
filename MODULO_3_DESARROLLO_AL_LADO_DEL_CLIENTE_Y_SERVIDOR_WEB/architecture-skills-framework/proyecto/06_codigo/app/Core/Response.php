<?php
declare(strict_types=1);

namespace App\Core;

use PDOException;
use RuntimeException;
use Throwable;

final class Response
{
    public static function json(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function fail(int $status, string $message): never
    {
        self::json($status, ['success' => false, 'error' => $message]);
    }

    /**
     * Patrón reutilizable para controllers de la API REST.
     * Captura PDOException primero para no filtrar detalles internos de BD,
     * traduce RuntimeException a 400/404 y cualquier otra Throwable a 500 sanitizado.
     */
    public static function execute(callable $action, int $status): never
    {
        try {
            $data = $action();
        } catch (PDOException $e) {
            error_log("DB Error: " . $e->getMessage());
            self::fail(500, 'Error de base de datos');
        } catch (RuntimeException $e) {
            $code = (int) $e->getCode();
            self::fail($code >= 400 ? $code : 400, $e->getMessage());
        } catch (Throwable $e) {
            error_log("Internal Error: " . $e->getMessage());
            self::fail(500, 'Error interno del servidor');
        }
        self::json($status, ['success' => true, 'data' => $data]);
    }
}
