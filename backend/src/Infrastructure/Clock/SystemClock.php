<?php

declare(strict_types=1);

namespace App\Infrastructure\Clock;

use App\Domain\Clock\ClockInterface;
use DateTimeImmutable;
use DateTimeZone;

final readonly class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
