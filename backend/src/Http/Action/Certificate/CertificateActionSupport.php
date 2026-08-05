<?php

declare(strict_types=1);

namespace App\Http\Action\Certificate;

use App\Domain\Exception\AuthenticationException;
use App\Domain\Exception\EntityNotFoundException;
use App\Domain\User\User;
use Psr\Http\Message\ServerRequestInterface;

trait CertificateActionSupport
{
    private function user(ServerRequestInterface $request): User
    {
        $user = $request->getAttribute('user');
        if (!$user instanceof User) {
            throw new AuthenticationException('Требуется access-токен');
        }

        return $user;
    }

    /**
     * @param array<string, string> $args
     */
    private function id(array $args): int
    {
        $id = filter_var($args['id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($id) || $id < 1) {
            throw new EntityNotFoundException('Сертификат не найден');
        }

        return $id;
    }
}
