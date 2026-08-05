<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Domain\Exception\ValidationException;
use Psr\Http\Message\ServerRequestInterface;

final class JsonBodyReader
{
    /**
     * @return array<string, mixed>
     */
    public function read(ServerRequestInterface $request): array
    {
        $body = (string) $request->getBody();
        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException(['body' => ['Некорректный JSON']]);
        }

        if (!is_array($decoded)) {
            throw new ValidationException(['body' => ['JSON должен быть объектом']]);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
