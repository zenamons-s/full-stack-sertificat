<?php

declare(strict_types=1);

namespace App\Application\Port;

interface RefreshTokenDenylist
{
    public function isDenied(string $jti): bool;

    public function deny(string $jti, int $ttlSeconds): void;
}
