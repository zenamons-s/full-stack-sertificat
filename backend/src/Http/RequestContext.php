<?php

declare(strict_types=1);

namespace App\Http;

final class RequestContext
{
    private string $requestId = '-';

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }
}
