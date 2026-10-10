<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Core/bootstrap.php';

use App\Core\Request;
use App\Core\Router;

$method = $argv[1] ?? 'GET';
$uri = $argv[2] ?? '/';
$payloadFile = $argv[3] ?? null;
$contentType = $argv[4] ?? 'application/json';

$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI'] = $uri;
$_SERVER['CONTENT_TYPE'] = $contentType;

if ($payloadFile !== null && file_exists($payloadFile)) {
    Request::$testInput = file_get_contents($payloadFile);
} else {
    Request::$testInput = null;
}

$router = new Router();
foreach (require dirname(__DIR__) . '/routes/api.php' as [$m, $pattern, $handler]) {
    $router->add($m, $pattern, $handler);
}

$router->dispatch(new Request());
