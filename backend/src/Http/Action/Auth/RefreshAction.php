<?php

declare(strict_types=1);

namespace App\Http\Action\Auth;

use App\Application\Auth\AuthService;
use App\Domain\Exception\ValidationException;
use App\Http\Request\JsonBodyReader;
use App\Http\Response\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RefreshAction
{
    public function __construct(
        private JsonBodyReader $bodyReader,
        private AuthService $auth,
        private JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->bodyReader->read($request);
        $refreshToken = $body['refresh_token'] ?? null;
        if (!is_string($refreshToken) || $refreshToken === '') {
            throw new ValidationException(['refresh_token' => ['Refresh-токен обязателен']]);
        }

        return $this->responder->json($this->auth->refresh($refreshToken));
    }
}
