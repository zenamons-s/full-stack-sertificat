<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Port\TokenIssuer;
use App\Domain\Exception\AuthenticationException;
use App\Domain\User\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class JwtAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TokenIssuer $tokens,
        private UserRepository $users,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (!str_starts_with($header, 'Bearer ')) {
            throw new AuthenticationException('Требуется access-токен');
        }

        $payload = $this->tokens->parseAccessToken(substr($header, 7));
        $user = $this->users->findById($payload->userId);
        if ($user === null) {
            throw new AuthenticationException('Пользователь не найден');
        }

        return $handler->handle($request->withAttribute('user', $user));
    }
}
