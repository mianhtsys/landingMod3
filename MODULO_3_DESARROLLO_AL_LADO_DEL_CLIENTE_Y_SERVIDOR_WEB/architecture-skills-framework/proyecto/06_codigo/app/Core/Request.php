<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    public static ?string $testInput = null;

    public readonly string $method;
    public readonly string $path;

    private array $params = [];
    private ?array $body = null;
    private bool $bodyParsed = false;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    }

    public function body(): ?array
    {
        if (!$this->bodyParsed) {
            $raw = self::$testInput !== null ? self::$testInput : file_get_contents('php://input');
            // CP-INT-04: solo se acepta JSON con Content-Type application/json
            // (bloquea payloads de formularios/fetch cross-site → defensa CSRF en profundidad)
            $ct = $_SERVER['CONTENT_TYPE'] ?? '';
            if ($raw !== '' && stripos($ct, 'application/json') !== 0) {
                throw new \RuntimeException('Content-Type debe ser application/json', 415);
            }
            $decoded = $raw === '' ? null : json_decode($raw, true);
            $this->body = is_array($decoded) ? $decoded : null;
            $this->bodyParsed = true;
        }
        return $this->body;
    }

    public static array $testHeaders = [];

    public function param(string $name): string
    {
        return $this->params[$name] ?? '';
    }

    public function header(string $name): ?string
    {
        $keyUpper = strtoupper(str_replace('-', '_', $name));
        if (isset(self::$testHeaders[$name])) {
            return (string) self::$testHeaders[$name];
        }
        if (isset(self::$testHeaders[$keyUpper])) {
            return (string) self::$testHeaders[$keyUpper];
        }

        $serverKey = 'HTTP_' . $keyUpper;
        if (isset($_SERVER[$serverKey])) {
            return (string) $_SERVER[$serverKey];
        }
        if (isset($_SERVER[$keyUpper])) {
            return (string) $_SERVER[$keyUpper];
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $k => $v) {
                if (strcasecmp($k, $name) === 0) {
                    return (string) $v;
                }
            }
        }

        return null;
    }

    public function ip(): string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            $ips = explode(',', $forwarded);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }
}
