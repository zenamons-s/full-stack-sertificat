<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Certificate\Certificate;
use App\Domain\Certificate\CertificateStatus;
use App\Domain\Certificate\Money;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\ValidationException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeClock;

final class CertificateTest extends TestCase
{
    private FakeClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FakeClock(new DateTimeImmutable('2026-08-06T00:00:00Z'));
    }

    public function testCreateRejectsBlankTitle(): void
    {
        $this->expectException(ValidationException::class);
        Certificate::create(' ', new Money(1000, 'RUB'), new DateTimeImmutable('2026-08-07T00:00:00Z'), 1, $this->clock);
    }

    public function testCreateRejectsNonPositivePrice(): void
    {
        $this->expectException(ValidationException::class);
        Certificate::create('Gift', new Money(0, 'RUB'), new DateTimeImmutable('2026-08-07T00:00:00Z'), 1, $this->clock);
    }

    public function testCreateRejectsPastExpiration(): void
    {
        $this->expectException(ValidationException::class);
        Certificate::create('Gift', new Money(1000, 'RUB'), new DateTimeImmutable('2026-08-05T23:59:59Z'), 1, $this->clock);
    }

    #[DataProvider('terminalStatuses')]
    public function testExtendValidityRejectsTerminalStatuses(CertificateStatus $status): void
    {
        $certificate = $this->certificate($status);

        $this->expectException(ConflictException::class);
        $certificate->extendValidity(new DateTimeImmutable('2026-08-10T00:00:00Z'), $this->clock);
    }

    public function testExtendValidityRenewsExpiredCertificateWithFutureDate(): void
    {
        $certificate = $this->certificate(CertificateStatus::Expired);

        $renewed = $certificate->extendValidity(new DateTimeImmutable('2026-08-10T00:00:00Z'), $this->clock);

        self::assertTrue($renewed);
        self::assertSame(CertificateStatus::Active, $certificate->status);
        self::assertSame('2026-08-10T00:00:00+00:00', $certificate->expiresAt->format(DATE_ATOM));
    }

    /**
     * @return iterable<string, array{CertificateStatus}>
     */
    public static function terminalStatuses(): iterable
    {
        yield 'redeemed' => [CertificateStatus::Redeemed];
        yield 'cancelled' => [CertificateStatus::Cancelled];
    }

    private function certificate(CertificateStatus $status): Certificate
    {
        return new Certificate(
            id: 1,
            title: 'Gift',
            price: new Money(1000, 'RUB'),
            expiresAt: new DateTimeImmutable('2026-08-05T00:00:00Z'),
            status: $status,
            version: 1,
            createdBy: 1,
            createdAt: new DateTimeImmutable('2026-08-01T00:00:00Z'),
            updatedAt: new DateTimeImmutable('2026-08-01T00:00:00Z'),
            deletedAt: null,
        );
    }
}
