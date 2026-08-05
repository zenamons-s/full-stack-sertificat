<?php

declare(strict_types=1);

use App\Http\Action\Auth\LoginAction;
use App\Http\Action\Auth\MeAction;
use App\Http\Action\Auth\RefreshAction;
use App\Http\Action\HealthAction;
use App\Http\Middleware\JwtAuthMiddleware;
use App\Http\Middleware\RateLimitMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    $app->get('/health', HealthAction::class);

    $app->group('/api/v1', function (RouteCollectorProxy $group): void {
        $group->get('/health', HealthAction::class);
        $group->post('/auth/login', LoginAction::class)->add(RateLimitMiddleware::class);
        $group->post('/auth/refresh', RefreshAction::class);
        $group->get('/auth/me', MeAction::class)->add(JwtAuthMiddleware::class);
    });
};
