<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Port\TokenIssuer;
use App\Application\Port\TokenPayload;
use App\Domain\Exception\AuthenticationException;
use App\Domain\User\User;
use App\Infrastructure\Config\Settings;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Ramsey\Uuid\Uuid;

final readonly class FirebaseJwtTokenIssuer implements TokenIssuer
{
    public function __construct(private Settings $settings)
    {
    }

    public function issueAccessToken(User $user): string
    {
        return $this->issue($user, 'access', $this->settings->jwtAccessTtl);
    }

    public function issueRefreshToken(User $user): string
    {
        return $this->issue($user, 'refresh', $this->settings->jwtRefreshTtl);
    }

    public function parseAccessToken(string $token): TokenPayload
    {
        return $this->parse($token, 'access');
    }

    public function parseRefreshToken(string $token): TokenPayload
    {
        return $this->parse($token, 'refresh');
    }

    private function issue(User $user, string $type, int $ttl): string
    {
        $now = time();
        return JWT::encode([
            'iss' => 'gift-certificates',
            'sub' => (string) $user->id,
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => Uuid::uuid7()->toString(),
            'typ' => $type,
        ], $this->settings->jwtSecret, 'HS256');
    }

    private function parse(string $token, string $expectedType): TokenPayload
    {
        try {
            $payload = JWT::decode($token, new Key($this->settings->jwtSecret, 'HS256'));
        } catch (ExpiredException) {
            throw new AuthenticationException('Токен истёк');
        } catch (\Throwable) {
            throw new AuthenticationException('Недействительный токен');
        }

        if (!isset($payload->sub, $payload->jti, $payload->exp, $payload->typ)) {
            throw new AuthenticationException('Недействительный токен');
        }

        $type = (string) $payload->typ;
        if ($type !== $expectedType) {
            throw new AuthenticationException('Недействительный тип токена');
        }

        return new TokenPayload(
            userId: (int) $payload->sub,
            jti: (string) $payload->jti,
            expiresAt: (int) $payload->exp,
            type: $type,
        );
    }
}
