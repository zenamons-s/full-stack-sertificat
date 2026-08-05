<?php

declare(strict_types=1);

namespace App\Domain\User;

use DateTimeImmutable;
use DateTimeZone;

final readonly class User
{
    public function __construct(
        public int $id,
        public string $email,
        public string $passwordHash,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @return array{id: int, email: string, created_at: string}
     */
    public function toResponse(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'created_at' => $this->createdAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
