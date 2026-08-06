<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Certificate\CertificateStatus;
use PHPUnit\Framework\TestCase;

final class CertificateStatusTest extends TestCase
{
    public function testStateMachineTransitions(): void
    {
        self::assertTrue(CertificateStatus::Active->canTransitionTo(CertificateStatus::Expired));
        self::assertTrue(CertificateStatus::Expired->canTransitionTo(CertificateStatus::Active));
        self::assertFalse(CertificateStatus::Redeemed->canTransitionTo(CertificateStatus::Active));
        self::assertFalse(CertificateStatus::Redeemed->canTransitionTo(CertificateStatus::Expired));
        self::assertFalse(CertificateStatus::Cancelled->canTransitionTo(CertificateStatus::Active));
        self::assertFalse(CertificateStatus::Cancelled->canTransitionTo(CertificateStatus::Expired));
    }
}
