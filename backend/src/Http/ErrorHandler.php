<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\Exception\AuthenticationException;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\EntityNotFoundException;
use App\Domain\Exception\RateLimitException;
use App\Domain\Exception\ValidationException;
use App\Http\Response\JsonResponder;
use App\Infrastructure\Config\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ErrorHandler
{
    public function __construct(
        private JsonResponder $responder,
        private Settings $settings,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        unset($displayErrorDetails, $logErrors, $logErrorDetails);

        [$status, $type, $title, $detail] = $this->map($exception);
        $requestId = (string) ($request->getAttribute('request_id') ?? '-');

        if ($status >= 500) {
            $this->logger->error('Unhandled exception', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }

        if ($this->settings->isProduction() && $status >= 500) {
            $detail = 'Внутренняя ошибка сервера';
        }

        $payload = [
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => $request->getUri()->getPath(),
            'request_id' => $requestId,
        ];

        if ($exception instanceof ValidationException) {
            $payload['errors'] = $exception->errors();
        }

        if (!$this->settings->isProduction() && $status >= 500) {
            $payload['exception'] = $exception::class;
        }

        return $this->responder->problem($payload, $status);
    }

    /**
     * @return array{int, string, string, string}
     */
    private function map(Throwable $exception): array
    {
        return match (true) {
            $exception instanceof EntityNotFoundException => [404, 'https://api.local/problems/not-found', 'Not found', $exception->getMessage()],
            $exception instanceof ValidationException => [422, 'https://api.local/problems/validation-error', 'Validation failed', $exception->getMessage()],
            $exception instanceof ConflictException => [409, 'https://api.local/problems/conflict', 'Conflict', $exception->getMessage()],
            $exception instanceof AuthenticationException => [401, 'https://api.local/problems/unauthorized', 'Unauthorized', $exception->getMessage()],
            $exception instanceof RateLimitException => [429, 'https://api.local/problems/rate-limit', 'Too many requests', $exception->getMessage()],
            default => [500, 'https://api.local/problems/internal-error', 'Internal server error', $exception->getMessage()],
        };
    }
}
