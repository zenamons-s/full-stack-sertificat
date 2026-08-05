<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Application\Port\RefreshTokenDenylist;
use App\Application\Port\TokenIssuer;
use App\Domain\Exception\AuthenticationException;
use App\Domain\User\User;
use App\Domain\User\UserRepository;

final readonly class AuthService
{
    public function __construct(
        private UserRepository $users,
        private TokenIssuer $tokens,
        private RefreshTokenDenylist $denylist,
        private int $accessTtl,
    ) {
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: 'Bearer', expires_in: int}
     */
    public function login(string $email, string $password): array
    {
        $user = $this->users->findByEmail($email);
        if ($user === null || !password_verify($password, $user->passwordHash)) {
            throw new AuthenticationException('Неверный email или пароль');
        }

        return $this->tokenPair($user);
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: 'Bearer', expires_in: int}
     */
    public function refresh(string $refreshToken): array
    {
        $payload = $this->tokens->parseRefreshToken($refreshToken);
        if ($this->denylist->isDenied($payload->jti)) {
            throw new AuthenticationException('Refresh-токен уже использован');
        }

        $user = $this->users->findById($payload->userId);
        if ($user === null) {
            throw new AuthenticationException('Пользователь не найден');
        }

        $this->denylist->deny($payload->jti, $payload->expiresAt - time());
        return $this->tokenPair($user);
    }

    /**
     * @return array{access_token: string, refresh_token: string, token_type: 'Bearer', expires_in: int}
     */
    private function tokenPair(User $user): array
    {
        return [
            'access_token' => $this->tokens->issueAccessToken($user),
            'refresh_token' => $this->tokens->issueRefreshToken($user),
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTtl,
        ];
    }
}
