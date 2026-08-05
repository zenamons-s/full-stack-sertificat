<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

final class DatabaseUrlParser
{
    public function parse(string $databaseUrl): DatabaseUrl
    {
        $parts = parse_url($databaseUrl);
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            throw new \RuntimeException('DATABASE_URL is invalid: expected pgsql://user:password@host:port/database');
        }

        $database = ltrim((string) $parts['path'], '/');
        if ($database === '') {
            throw new \RuntimeException('DATABASE_URL is invalid: database name is missing');
        }

        return new DatabaseUrl(
            host: (string) $parts['host'],
            port: isset($parts['port']) ? (int) $parts['port'] : 5432,
            user: isset($parts['user']) ? rawurldecode((string) $parts['user']) : '',
            password: isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : '',
            database: $database,
        );
    }
}
