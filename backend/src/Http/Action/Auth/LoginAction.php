<?php

declare(strict_types=1);

namespace App\Http\Action\Auth;

use App\Application\Auth\AuthService;
use App\Domain\Exception\ValidationException;
use App\Http\Request\JsonBodyReader;
use App\Http\Response\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class LoginAction
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
        $email = $body['email'] ?? null;
        $password = $body['password'] ?? null;
        $errors = [];

        if (!is_string($email) || trim($email) === '') {
            $errors['email'][] = 'Email обязателен';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'][] = 'Email должен быть корректным';
        }

        if (!is_string($password) || $password === '') {
            $errors['password'][] = 'Пароль обязателен';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->responder->json($this->auth->login(trim($email), $password));
    }
}
