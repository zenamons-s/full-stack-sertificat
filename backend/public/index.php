<?php

declare(strict_types=1);

use App\Http\ErrorHandler;
use App\Http\Middleware\RequestIdMiddleware;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;
use Slim\Factory\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

try {
    $settingsFactory = require dirname(__DIR__) . '/config/settings.php';
    $settings = $settingsFactory();
    $containerFactory = require dirname(__DIR__) . '/config/container.php';
    $container = $containerFactory($settings);
    if (!$container instanceof ContainerInterface) {
        throw new RuntimeException('Container factory must return PSR container');
    }

    AppFactory::setContainer($container);
    $app = AppFactory::create();

    $routes = require dirname(__DIR__) . '/config/routes.php';
    $routes($app);

    $app->addRoutingMiddleware();
    $errorMiddleware = $app->addErrorMiddleware(!$settings->isProduction(), true, true);
    $errorMiddleware->setDefaultErrorHandler($container->get(ErrorHandler::class));
    $app->add(RequestIdMiddleware::class);

    $app->run();
} catch (Throwable $exception) {
    http_response_code(500);
    header('Content-Type: application/problem+json');
    echo json_encode([
        'type' => 'https://api.local/problems/bootstrap-error',
        'title' => 'Application bootstrap failed',
        'status' => 500,
        'detail' => $exception->getMessage(),
        'instance' => $_SERVER['REQUEST_URI'] ?? '/',
        'request_id' => '-',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
