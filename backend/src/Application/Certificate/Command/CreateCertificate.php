<?php

declare(strict_types=1);

namespace App\Application\Certificate\Command;

use DateTimeImmutable;

final readonly class CreateCertificate
{
    public function __construct(
        public string $title,
        public int $priceMinor,
        public string $currency,
        public DateTimeImmutable $expiresAt,
        public int $actorId,
        public string $requestId,
    ) {
    }
}
