<?php

declare(strict_types=1);

namespace App\Domain\Certificate;

interface CertificateRepository
{
    public function nextId(): int;

    public function add(Certificate $certificate): Certificate;

    public function find(int $id): Certificate;

    public function findWithTrashed(int $id): Certificate;

    public function list(CertificateListFilter $filter, int $userId): PaginatedCertificates;

    public function save(Certificate $certificate, int $expectedVersion): bool;
}
