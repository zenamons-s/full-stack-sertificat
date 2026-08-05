<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Application\Certificate\Query\ListCertificatesQuery;
use App\Application\Port\CertificateListCache;
use App\Infrastructure\Config\Settings;
use Predis\Client;
use Psr\Log\LoggerInterface;

final readonly class RedisCertificateListCache implements CertificateListCache
{
    public function __construct(
        private Client $redis,
        private Settings $settings,
        private LoggerInterface $logger,
    ) {
    }

    public function get(ListCertificatesQuery $query): ?array
    {
        try {
            $value = $this->redis->get($this->key($query));
            if (!is_string($value)) {
                $this->logger->info('certificate list cache miss');
                return null;
            }

            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return null;
            }

            $this->logger->info('certificate list cache hit');
            /** @var array<string, mixed> $decoded */
            return $decoded;
        } catch (\Throwable $exception) {
            $this->logger->warning('certificate list cache read failed', ['exception' => $exception::class]);
            return null;
        }
    }

    public function set(ListCertificatesQuery $query, array $payload): void
    {
        try {
            $this->redis->setex($this->key($query), $this->settings->cacheTtl, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $this->logger->info('certificate list cache store');
        } catch (\Throwable $exception) {
            $this->logger->warning('certificate list cache write failed', ['exception' => $exception::class]);
        }
    }

    public function invalidate(): void
    {
        try {
            $this->redis->incr('certificates:gen');
            $this->logger->info('certificate list cache invalidated');
        } catch (\Throwable $exception) {
            $this->logger->warning('certificate list cache invalidation failed', ['exception' => $exception::class]);
        }
    }

    private function key(ListCertificatesQuery $query): string
    {
        $gen = '0';
        try {
            $value = $this->redis->get('certificates:gen');
            $gen = is_string($value) ? $value : '0';
        } catch (\Throwable $exception) {
            $this->logger->warning('certificate list cache generation failed', ['exception' => $exception::class]);
        }

        return 'certificates:list:' . $gen . ':' . $query->userId . ':' . hash('sha256', json_encode([
            'search' => $query->search,
            'status' => $query->status,
            'trashed' => $query->trashed,
            'page' => $query->page,
            'per_page' => $query->perPage,
            'sort' => $query->sort,
        ], JSON_THROW_ON_ERROR));
    }
}
