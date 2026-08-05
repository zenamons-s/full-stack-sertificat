<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Application\Port\RefreshTokenDenylist;
use Predis\Client;
use Psr\Log\LoggerInterface;

final readonly class RedisRefreshTokenDenylist implements RefreshTokenDenylist
{
    public function __construct(
        private Client $redis,
        private LoggerInterface $logger,
    ) {
    }

    public function isDenied(string $jti): bool
    {
        try {
            return (bool) $this->redis->exists($this->key($jti));
        } catch (\Throwable $exception) {
            $this->logger->warning('Redis denylist check failed', ['exception' => $exception::class]);
            return false;
        }
    }

    public function deny(string $jti, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }

        try {
            $this->redis->setex($this->key($jti), $ttlSeconds, '1');
        } catch (\Throwable $exception) {
            $this->logger->warning('Redis denylist write failed', ['exception' => $exception::class]);
        }
    }

    private function key(string $jti): string
    {
        return 'auth:refresh:deny:' . $jti;
    }
}
