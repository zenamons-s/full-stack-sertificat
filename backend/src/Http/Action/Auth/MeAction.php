<?php

declare(strict_types=1);

namespace App\Http\Action\Auth;

use App\Domain\Exception\AuthenticationException;
use App\Domain\User\User;
use App\Http\Response\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class MeAction
{
    public function __construct(private JsonResponder $responder)
    {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if (!$user instanceof User) {
            throw new AuthenticationException('Требуется access-токен');
        }

        return $this->responder->json($user->toResponse());
    }
}
