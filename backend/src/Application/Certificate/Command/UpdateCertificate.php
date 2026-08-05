<?php

declare(strict_types=1);

namespace App\Application\Certificate\Command;

use DateTimeImmutable;

final readonly class UpdateCertificate
{
    public function __construct(
        public int $id,
        public ?string $title,
        public ?int $priceMinor,
        public ?string $currency,
        public ?DateTimeImmutable $expiresAt,
        public int $version,
        public int $actorId,
        public string $requestId,
    ) {
    }
}
