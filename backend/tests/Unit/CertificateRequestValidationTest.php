<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Exception\ValidationException;
use App\Http\Request\CreateCertificateRequest;
use App\Http\Request\JsonBodyReader;
use App\Http\Request\UpdateCertificateRequest;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Tests\Support\FakeClock;

final class CertificateRequestValidationTest extends TestCase
{
    public function testCreateRequestCollectsAllValidationErrors(): void
    {
        $request = new CreateCertificateRequest(new JsonBodyReader(), $this->clock());

        try {
            $request->toCommand($this->jsonRequest([
                'status' => 'active',
                'title' => '',
                'price_minor' => 0,
                'currency' => 'rub',
                'expires_at' => '2026-08-05T00:00:00Z',
            ]), 1);
            self::fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            self::assertSame(['status', 'title', 'price_minor', 'currency', 'expires_at'], array_keys($exception->errors()));
        }
    }

    public function testUpdateRequestCollectsAllValidationErrors(): void
    {
        $request = new UpdateCertificateRequest(new JsonBodyReader(), $this->clock());

        try {
            $request->toCommand($this->jsonRequest([
                'status' => 'cancelled',
                'title' => '',
                'price_minor' => 0,
                'currency' => 'EURO',
                'expires_at' => 'not-a-date',
                'version' => 0,
            ]), 1, 1);
            self::fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            self::assertSame(['status', 'title', 'price_minor', 'currency', 'expires_at', 'version'], array_keys($exception->errors()));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRequest(array $payload): \Psr\Http\Message\ServerRequestInterface
    {
        $body = (new StreamFactory())->createStream(json_encode($payload, JSON_THROW_ON_ERROR));

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/certificates')
            ->withAttribute('request_id', 'unit-test')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);
    }

    private function clock(): FakeClock
    {
        return new FakeClock(new DateTimeImmutable('2026-08-06T00:00:00Z'));
    }
}
