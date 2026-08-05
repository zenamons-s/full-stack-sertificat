<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    public function __construct(private RequestContext $context)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $incoming = trim($request->getHeaderLine('X-Request-Id'));
        $requestId = $incoming !== '' ? $incoming : $this->generateUlid();
        $this->context->setRequestId($requestId);

        return $handler
            ->handle($request->withAttribute('request_id', $requestId))
            ->withHeader('X-Request-Id', $requestId);
    }

    private function generateUlid(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time = (int) floor(microtime(true) * 1000);
        $chars = '';

        for ($i = 9; $i >= 0; $i--) {
            $chars = $alphabet[$time % 32] . $chars;
            $time = intdiv($time, 32);
        }

        $random = random_bytes(10);
        $value = 0;
        $bits = 0;
        for ($i = 0; $i < 10; $i++) {
            $value = ($value << 8) | ord($random[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $chars .= $alphabet[($value >> $bits) & 31];
            }
        }

        return substr($chars, 0, 26);
    }
}
