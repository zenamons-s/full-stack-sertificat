<?php

declare(strict_types=1);

namespace App\Domain\Certificate;

final readonly class PaginatedCertificates
{
    /**
     * @param list<Certificate> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    public function totalPages(): int
    {
        return $this->total === 0 ? 0 : (int) ceil($this->total / $this->perPage);
    }
}
