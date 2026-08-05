<?php

declare(strict_types=1);

namespace App\Application\Certificate;

use App\Domain\Certificate\Certificate;
use DateTimeImmutable;
use DateTimeZone;

final class CertificateResponseMapper
{
    /**
     * @return array<string, mixed>
     */
    public function map(Certificate $certificate): array
    {
        return [
            'id' => $certificate->id,
            'title' => $certificate->title,
            'price_minor' => $certificate->price->minor,
            'currency' => $certificate->price->currency,
            'price_formatted' => $certificate->price->formatted(),
            'expires_at' => $this->format($certificate->expiresAt),
            'status' => $certificate->status->value,
            'version' => $certificate->version,
            'created_at' => $this->format($certificate->createdAt),
            'updated_at' => $this->format($certificate->updatedAt),
            'deleted_at' => $certificate->deletedAt === null ? null : $this->format($certificate->deletedAt),
        ];
    }

    private function format(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
