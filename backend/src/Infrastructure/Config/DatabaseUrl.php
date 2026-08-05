<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

final readonly class DatabaseUrl
{
    public function __construct(
        public string $host,
        public int $port,
        public string $user,
        public string $password,
        public string $database,
    ) {
    }

    /**
     * @return array{driver: 'pdo_pgsql', host: string, port: int, user: string, password: string, dbname: string}
     */
    public function toDbalParams(): array
    {
        return [
            'driver' => 'pdo_pgsql',
            'host' => $this->host,
            'port' => $this->port,
            'user' => $this->user,
            'password' => $this->password,
            'dbname' => $this->database,
        ];
    }
}
