<?php

declare(strict_types=1);

namespace App\Application\Port;

final readonly class TokenPayload
{
    public function __construct(
        public int $userId,
        public string $jti,
        public int $expiresAt,
        public string $type,
    ) {
    }
}
