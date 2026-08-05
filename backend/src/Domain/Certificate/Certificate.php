<?php

declare(strict_types=1);

namespace App\Domain\Certificate;

use App\Domain\Clock\ClockInterface;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\ValidationException;
use DateTimeImmutable;

final class Certificate
{
    public function __construct(
        public ?int $id,
        public string $title,
        public Money $price,
        public DateTimeImmutable $expiresAt,
        public CertificateStatus $status,
        public int $version,
        public int $createdBy,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {
        $this->assertValidTitle($title);
        $this->title = trim($title);
        if ($version < 1) {
            throw new ValidationException(['version' => ['Должно быть больше нуля']]);
        }
    }

    public static function create(
        string $title,
        Money $price,
        DateTimeImmutable $expiresAt,
        int $createdBy,
        ClockInterface $clock,
    ): self {
        $now = $clock->now();
        if ($expiresAt <= $now) {
            throw new ValidationException(['expires_at' => ['Должно быть датой в будущем']]);
        }

        return new self(null, $title, $price, $expiresAt, CertificateStatus::Active, 1, $createdBy, $now, $now, null);
    }

    public function rename(string $title): void
    {
        $this->assertNotTerminal();
        $this->assertValidTitle($title);
        $this->title = trim($title);
    }

    public function changePrice(Money $price): void
    {
        $this->assertNotTerminal();
        $this->price = $price;
    }

    public function extendValidity(DateTimeImmutable $expiresAt, ClockInterface $clock): bool
    {
        $this->assertNotTerminal();
        if ($expiresAt <= $clock->now()) {
            throw new ValidationException(['expires_at' => ['Должно быть датой в будущем']]);
        }

        $renewed = false;
        if ($this->status === CertificateStatus::Expired) {
            if (!$this->status->canTransitionTo(CertificateStatus::Active)) {
                throw new ConflictException('Недопустимый переход статуса');
            }
            $this->status = CertificateStatus::Active;
            $renewed = true;
        }

        $this->expiresAt = $expiresAt;
        return $renewed;
    }

    public function softDelete(ClockInterface $clock): void
    {
        if ($this->deletedAt === null) {
            $this->deletedAt = $clock->now();
        }
    }

    public function restore(ClockInterface $clock): void
    {
        unset($clock);
        if ($this->deletedAt === null) {
            throw new ConflictException('Сертификат не находится в корзине');
        }

        $this->deletedAt = null;
    }

    public function touch(DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }

    private function assertNotTerminal(): void
    {
        if (in_array($this->status, [CertificateStatus::Redeemed, CertificateStatus::Cancelled], true)) {
            throw new ConflictException('Сертификат в терминальном статусе нельзя изменить');
        }
    }

    private function assertValidTitle(string $title): void
    {
        $trimmed = trim($title);
        if ($trimmed === '') {
            throw new ValidationException(['title' => ['Не должно быть пустым']]);
        }
        if (mb_strlen($trimmed) > 255) {
            throw new ValidationException(['title' => ['Не должно быть длиннее 255 символов']]);
        }
    }
}
