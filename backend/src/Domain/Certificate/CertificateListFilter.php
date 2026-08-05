<?php

declare(strict_types=1);

namespace App\Domain\Certificate;

final readonly class CertificateListFilter
{
    public function __construct(
        public ?string $search,
        public ?CertificateStatus $status,
        public string $trashed,
        public int $page,
        public int $perPage,
        public string $sort,
    ) {
    }
}
