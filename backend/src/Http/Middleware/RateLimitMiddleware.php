<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Exception\AuthenticationException;
use App\Domain\Exception\RateLimitException;
use Predis\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final readonly class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Client $redis,
        private LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $key = 'rate:auth-login:' . (is_string($ip) ? $ip : 'unknown');

        try {
            return $handler->handle($request);
        } catch (AuthenticationException) {
            try {
                $attempts = (int) $this->redis->incr($key);
                if ($attempts === 1) {
                    $this->redis->expire($key, 60);
                }
                if ($attempts > 5) {
                    throw new RateLimitException('Слишком много попыток входа');
                }
            } catch (RateLimitException $exception) {
                throw $exception;
            } catch (\Throwable $exception) {
                $this->logger->warning('Redis rate limit failed', ['exception' => $exception::class]);
            }

            throw new AuthenticationException('Неверный email или пароль');
        }
    }
}
