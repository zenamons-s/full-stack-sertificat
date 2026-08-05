<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

final readonly class Settings
{
    public const VERSION = '0.1.0';

    public function __construct(
        public string $appEnv,
        public DatabaseUrl $database,
        public string $databaseUrl,
        public string $redisUrl,
        public string $jwtSecret,
        public int $jwtAccessTtl,
        public int $jwtRefreshTtl,
        public string $logLevel,
        public int $cacheTtl,
    ) {
    }

    /**
     * @param array<string, string> $env
     */
    public static function fromEnv(array $env, DatabaseUrlParser $databaseUrlParser): self
    {
        $required = [
            'APP_ENV',
            'DATABASE_URL',
            'REDIS_URL',
            'JWT_SECRET',
            'JWT_ACCESS_TTL',
            'JWT_REFRESH_TTL',
        ];

        foreach ($required as $name) {
            if (($env[$name] ?? '') === '') {
                throw new \RuntimeException(sprintf('%s is required', $name));
            }
        }

        if (strlen($env['JWT_SECRET']) < 32) {
            throw new \RuntimeException('JWT_SECRET must be at least 32 characters long');
        }

        return new self(
            appEnv: $env['APP_ENV'],
            database: $databaseUrlParser->parse($env['DATABASE_URL']),
            databaseUrl: $env['DATABASE_URL'],
            redisUrl: $env['REDIS_URL'],
            jwtSecret: $env['JWT_SECRET'],
            jwtAccessTtl: self::positiveInt($env['JWT_ACCESS_TTL'], 'JWT_ACCESS_TTL'),
            jwtRefreshTtl: self::positiveInt($env['JWT_REFRESH_TTL'], 'JWT_REFRESH_TTL'),
            logLevel: $env['LOG_LEVEL'] ?? 'info',
            cacheTtl: self::positiveInt($env['CACHE_TTL'] ?? '60', 'CACHE_TTL'),
        );
    }

    private static function positiveInt(string $value, string $name): int
    {
        $intValue = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($intValue) || $intValue <= 0) {
            throw new \RuntimeException(sprintf('%s must be a positive integer', $name));
        }

        return $intValue;
    }

    public function isProduction(): bool
    {
        return $this->appEnv === 'production';
    }
}
