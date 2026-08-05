<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\Certificate\Query\ListCertificatesQuery;

interface CertificateListCache
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(ListCertificatesQuery $query): ?array;

    /**
     * @param array<string, mixed> $payload
     */
    public function set(ListCertificatesQuery $query, array $payload): void;

    public function invalidate(): void;
}
