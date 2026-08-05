<?php

declare(strict_types=1);

namespace App\Http\Response;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

final readonly class JsonResponder
{
    public function __construct(private ResponseFactoryInterface $responseFactory)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public function json(array $payload, int $status = 200, array $headers = []): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function problem(array $payload, int $status): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/problem+json');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        return $response;
    }
}
