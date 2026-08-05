<?php

declare(strict_types=1);

namespace App\Http\Action;

use App\Http\Response\JsonResponder;
use App\Infrastructure\Config\Settings;
use Doctrine\DBAL\Connection;
use Predis\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class HealthAction
{
    public function __construct(
        private Connection $connection,
        private Client $redis,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        unset($request);

        $db = 'ok';
        $redis = 'ok';

        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
        } catch (\Throwable) {
            $db = 'error';
        }

        try {
            $this->redis->ping();
        } catch (\Throwable) {
            $redis = 'degraded';
        }

        $status = $db === 'ok' ? 200 : 503;

        return $this->responder->json([
            'status' => $db === 'ok' ? 'ok' : 'degraded',
            'db' => $db,
            'redis' => $redis,
            'version' => Settings::VERSION,
        ], $status);
    }
}
