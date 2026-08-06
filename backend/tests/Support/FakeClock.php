<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Clock\ClockInterface;
use DateTimeImmutable;

final readonly class FakeClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
