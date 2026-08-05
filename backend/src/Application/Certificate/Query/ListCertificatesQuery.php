<?php

declare(strict_types=1);

namespace App\Application\Certificate\Query;

final readonly class ListCertificatesQuery
{
    public function __construct(
        public ?string $search,
        public ?string $status,
        public string $trashed,
        public int $page,
        public int $perPage,
        public string $sort,
        public int $userId,
    ) {
    }
}
