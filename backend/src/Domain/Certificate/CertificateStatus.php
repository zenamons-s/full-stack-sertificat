<?php

declare(strict_types=1);

namespace App\Domain\Certificate;

enum CertificateStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Redeemed = 'redeemed';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::Active => in_array($target, [self::Expired, self::Redeemed, self::Cancelled], true),
            self::Expired => $target === self::Active,
            self::Redeemed, self::Cancelled => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
