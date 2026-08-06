<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Application\Certificate\Command\UpdateCertificate;
use App\Domain\Clock\ClockInterface;
use App\Domain\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ServerRequestInterface;

final readonly class UpdateCertificateRequest
{
    public function __construct(private JsonBodyReader $reader, private ClockInterface $clock)
    {
    }

    public function toCommand(ServerRequestInterface $request, int $id, int $actorId): UpdateCertificate
    {
        $body = $this->reader->read($request);
        $errors = [];

        foreach (array_diff(array_keys($body), ['title', 'price_minor', 'currency', 'expires_at', 'version']) as $field) {
            $errors[(string) $field][] = 'Поле не принимается';
        }

        $title = null;
        if (array_key_exists('title', $body)) {
            if (!is_string($body['title'])) {
                $errors['title'][] = 'Должно быть строкой';
            } else {
                $title = $body['title'];
                if (trim($title) === '') {
                    $errors['title'][] = 'Не должно быть пустым';
                } elseif (mb_strlen(trim($title)) > 255) {
                    $errors['title'][] = 'Не должно быть длиннее 255 символов';
                }
            }
        }

        $priceMinor = null;
        if (array_key_exists('price_minor', $body)) {
            if (!is_int($body['price_minor'])) {
                $errors['price_minor'][] = 'Должно быть целым числом';
            } else {
                $priceMinor = $body['price_minor'];
                if ($priceMinor <= 0) {
                    $errors['price_minor'][] = 'Должно быть больше нуля';
                }
            }
        }

        $currency = null;
        if (array_key_exists('currency', $body)) {
            if (!is_string($body['currency'])) {
                $errors['currency'][] = 'Должно быть строкой';
            } else {
                $currency = $body['currency'];
                if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                    $errors['currency'][] = 'Должно быть ISO-кодом из трёх заглавных букв';
                }
            }
        }

        $expiresAt = null;
        if (array_key_exists('expires_at', $body)) {
            if (!is_string($body['expires_at'])) {
                $errors['expires_at'][] = 'Должно быть датой';
            } else {
                try {
                    $expiresAt = (new DateTimeImmutable($body['expires_at']))->setTimezone(new DateTimeZone('UTC'));
                    if ($expiresAt <= $this->clock->now()) {
                        $errors['expires_at'][] = 'Должно быть датой в будущем';
                    }
                } catch (\Throwable) {
                    $errors['expires_at'][] = 'Должно быть датой';
                }
            }
        }

        $version = null;
        if (!array_key_exists('version', $body)) {
            $errors['version'][] = 'Обязательное поле';
        } elseif (!is_int($body['version']) || $body['version'] < 1) {
            $errors['version'][] = 'Должно быть целым числом больше нуля';
        } else {
            $version = $body['version'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new UpdateCertificate($id, $title === null ? null : trim($title), $priceMinor, $currency, $expiresAt, $version ?? 1, $actorId, (string) $request->getAttribute('request_id'));
    }
}
