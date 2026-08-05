<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\User\User;

interface TokenIssuer
{
    public function issueAccessToken(User $user): string;

    public function issueRefreshToken(User $user): string;

    public function parseAccessToken(string $token): TokenPayload;

    public function parseRefreshToken(string $token): TokenPayload;
}
