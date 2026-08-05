<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Application\Certificate\Command\CreateCertificate;
use App\Domain\Clock\ClockInterface;
use App\Domain\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CreateCertificateRequest
{
    public function __construct(private JsonBodyReader $reader, private ClockInterface $clock)
    {
    }

    public function toCommand(ServerRequestInterface $request, int $actorId): CreateCertificate
    {
        $body = $this->reader->read($request);
        $errors = [];

        if (array_key_exists('status', $body)) {
            $errors['status'][] = 'Поле не принимается';
        }

        $title = $this->stringField($body, 'title', $errors);
        $priceMinor = $this->intField($body, 'price_minor', $errors);
        $currency = $this->optionalString($body, 'currency', 'RUB', $errors);
        $expiresAt = $this->dateField($body, 'expires_at', $errors);

        if ($title !== null) {
            $trimmed = trim($title);
            if ($trimmed === '') {
                $errors['title'][] = 'Не должно быть пустым';
            } elseif (mb_strlen($trimmed) > 255) {
                $errors['title'][] = 'Не должно быть длиннее 255 символов';
            }
        }
        if ($priceMinor !== null && $priceMinor <= 0) {
            $errors['price_minor'][] = 'Должно быть больше нуля';
        }
        if ($currency !== null && !preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors['currency'][] = 'Должно быть ISO-кодом из трёх заглавных букв';
        }
        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            $errors['expires_at'][] = 'Должно быть датой в будущем';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new CreateCertificate($title ?? '', $priceMinor ?? 0, $currency ?? 'RUB', $expiresAt ?? $this->clock->now(), $actorId, (string) $request->getAttribute('request_id'));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, list<string>> $errors
     */
    private function stringField(array $body, string $field, array &$errors): ?string
    {
        if (!array_key_exists($field, $body)) {
            $errors[$field][] = 'Обязательное поле';
            return null;
        }
        if (!is_string($body[$field])) {
            $errors[$field][] = 'Должно быть строкой';
            return null;
        }

        return $body[$field];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, list<string>> $errors
     */
    private function optionalString(array $body, string $field, string $default, array &$errors): ?string
    {
        if (!array_key_exists($field, $body)) {
            return $default;
        }
        if (!is_string($body[$field])) {
            $errors[$field][] = 'Должно быть строкой';
            return null;
        }

        return $body[$field];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, list<string>> $errors
     */
    private function intField(array $body, string $field, array &$errors): ?int
    {
        if (!array_key_exists($field, $body)) {
            $errors[$field][] = 'Обязательное поле';
            return null;
        }
        if (!is_int($body[$field])) {
            $errors[$field][] = 'Должно быть целым числом';
            return null;
        }

        return $body[$field];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, list<string>> $errors
     */
    private function dateField(array $body, string $field, array &$errors): ?DateTimeImmutable
    {
        if (!array_key_exists($field, $body)) {
            $errors[$field][] = 'Обязательное поле';
            return null;
        }
        if (!is_string($body[$field])) {
            $errors[$field][] = 'Должно быть датой';
            return null;
        }

        try {
            return (new DateTimeImmutable($body[$field]))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Throwable) {
            $errors[$field][] = 'Должно быть датой';
            return null;
        }
    }
}
