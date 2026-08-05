<?php

declare(strict_types=1);

use App\Http\Action\Auth\LoginAction;
use App\Http\Action\Auth\MeAction;
use App\Http\Action\Auth\RefreshAction;
use App\Http\Action\Certificate\AuditAction;
use App\Http\Action\Certificate\CreateAction;
use App\Http\Action\Certificate\DeleteAction;
use App\Http\Action\Certificate\ListAction;
use App\Http\Action\Certificate\RestoreAction;
use App\Http\Action\Certificate\ShowAction;
use App\Http\Action\Certificate\UpdateAction;
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

        $group->get('/certificates', ListAction::class)->add(JwtAuthMiddleware::class);
        $group->post('/certificates', CreateAction::class)->add(JwtAuthMiddleware::class);
        $group->get('/certificates/{id}', ShowAction::class)->add(JwtAuthMiddleware::class);
        $group->patch('/certificates/{id}', UpdateAction::class)->add(JwtAuthMiddleware::class);
        $group->delete('/certificates/{id}', DeleteAction::class)->add(JwtAuthMiddleware::class);
        $group->post('/certificates/{id}/restore', RestoreAction::class)->add(JwtAuthMiddleware::class);
        $group->get('/certificates/{id}/audit', AuditAction::class)->add(JwtAuthMiddleware::class);
    });
};
