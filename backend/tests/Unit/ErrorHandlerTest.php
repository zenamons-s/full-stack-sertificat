<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Exception\AuthenticationException;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\EntityNotFoundException;
use App\Domain\Exception\RateLimitException;
use App\Domain\Exception\ValidationException;
use App\Http\ErrorHandler;
use App\Http\Response\JsonResponder;
use App\Infrastructure\Config\DatabaseUrl;
use App\Infrastructure\Config\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Throwable;

final class ErrorHandlerTest extends TestCase
{
    #[DataProvider('exceptions')]
    public function testMapsDomainExceptionsToProblemStatuses(Throwable $exception, int $expectedStatus): void
    {
        $handler = new ErrorHandler(new JsonResponder(new ResponseFactory()), $this->settings(), new NullLogger());
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/certificates/404')
            ->withAttribute('request_id', 'req-test');

        $response = $handler($request, $exception, true, true, true);
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($expectedStatus, $response->getStatusCode());
        self::assertSame($expectedStatus, $payload['status']);
        self::assertSame('/api/v1/certificates/404', $payload['instance']);
    }

    /**
     * @return iterable<string, array{Throwable, int}>
     */
    public static function exceptions(): iterable
    {
        yield 'not found' => [new EntityNotFoundException('missing'), 404];
        yield 'validation' => [new ValidationException(['title' => ['bad']]), 422];
        yield 'conflict' => [new ConflictException('changed'), 409];
        yield 'auth' => [new AuthenticationException('bad credentials'), 401];
        yield 'rate limit' => [new RateLimitException('slow down'), 429];
        yield 'unknown' => [new RuntimeException('boom'), 500];
    }

    private function settings(): Settings
    {
        return new Settings(
            appEnv: 'test',
            database: new DatabaseUrl('localhost', 5432, 'app', 'password', 'test'),
            databaseUrl: 'pgsql://app:password@localhost:5432/test',
            redisUrl: 'redis://redis:6379',
            jwtSecret: 'test_secret_which_is_long_enough',
            jwtAccessTtl: 900,
            jwtRefreshTtl: 1209600,
            logLevel: 'error',
            cacheTtl: 60,
        );
    }
}
