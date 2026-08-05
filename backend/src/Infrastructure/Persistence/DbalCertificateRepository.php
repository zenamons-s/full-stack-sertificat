<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Certificate\Certificate;
use App\Domain\Certificate\CertificateListFilter;
use App\Domain\Certificate\CertificateRepository;
use App\Domain\Certificate\CertificateStatus;
use App\Domain\Certificate\Money;
use App\Domain\Certificate\PaginatedCertificates;
use App\Domain\Exception\EntityNotFoundException;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DbalCertificateRepository implements CertificateRepository
{
    /**
     * @var array<string, string>
     */
    private const SORT_COLUMNS = [
        'created_at' => 'created_at ASC, id ASC',
        '-created_at' => 'created_at DESC, id DESC',
        'expires_at' => 'expires_at ASC, id ASC',
        '-expires_at' => 'expires_at DESC, id DESC',
        'price_minor' => 'price_minor ASC, id ASC',
        '-price_minor' => 'price_minor DESC, id DESC',
        'title' => 'title ASC, id ASC',
        '-title' => 'title DESC, id DESC',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function nextId(): int
    {
        return (int) $this->connection->fetchOne("SELECT nextval('certificates_id_seq')");
    }

    public function add(Certificate $certificate): Certificate
    {
        $id = $certificate->id ?? $this->nextId();
        $this->connection->insert('certificates', [
            'id' => $id,
            'title' => $certificate->title,
            'price_minor' => $certificate->price->minor,
            'currency' => $certificate->price->currency,
            'expires_at' => $this->dbTime($certificate->expiresAt),
            'status' => $certificate->status->value,
            'version' => $certificate->version,
            'created_by' => $certificate->createdBy,
            'created_at' => $this->dbTime($certificate->createdAt),
            'updated_at' => $this->dbTime($certificate->updatedAt),
            'deleted_at' => null,
        ]);

        return $this->findWithTrashed($id);
    }

    public function find(int $id): Certificate
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative('SELECT * FROM certificates WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
        if ($row === false) {
            throw new EntityNotFoundException('Сертификат не найден');
        }

        return $this->hydrate($row);
    }

    public function findWithTrashed(int $id): Certificate
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative('SELECT * FROM certificates WHERE id = :id', ['id' => $id]);
        if ($row === false) {
            throw new EntityNotFoundException('Сертификат не найден');
        }

        return $this->hydrate($row);
    }

    public function list(CertificateListFilter $filter, int $userId): PaginatedCertificates
    {
        $where = ['created_by = :user_id'];
        $params = ['user_id' => $userId];
        $types = ['user_id' => ParameterType::INTEGER];

        if ($filter->trashed === 'none') {
            $where[] = 'deleted_at IS NULL';
        } elseif ($filter->trashed === 'only') {
            $where[] = 'deleted_at IS NOT NULL';
        }

        if ($filter->search !== null && trim($filter->search) !== '') {
            $where[] = "title ILIKE '%' || :search || '%'";
            $params['search'] = trim($filter->search);
        }

        if ($filter->status !== null) {
            $where[] = 'status = :status';
            $params['status'] = $filter->status->value;
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM certificates WHERE {$whereSql}", $params, $types);
        $offset = ($filter->page - 1) * $filter->perPage;
        $params['limit'] = $filter->perPage;
        $params['offset'] = $offset;
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;
        $orderBy = self::SORT_COLUMNS[$filter->sort] ?? self::SORT_COLUMNS['-created_at'];

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM certificates WHERE {$whereSql} ORDER BY {$orderBy} LIMIT :limit OFFSET :offset",
            $params,
            $types,
        );

        return new PaginatedCertificates(array_map(fn (array $row): Certificate => $this->hydrate($row), $rows), $filter->page, $filter->perPage, $total);
    }

    public function save(Certificate $certificate, int $expectedVersion): bool
    {
        $affected = $this->connection->executeStatement(
            <<<'SQL'
            UPDATE certificates
            SET title = :title,
                price_minor = :price_minor,
                currency = :currency,
                expires_at = :expires_at,
                status = :status,
                version = version + 1,
                updated_at = :updated_at,
                deleted_at = :deleted_at
            WHERE id = :id AND version = :version
            SQL,
            [
                'id' => $certificate->id,
                'title' => $certificate->title,
                'price_minor' => $certificate->price->minor,
                'currency' => $certificate->price->currency,
                'expires_at' => $this->dbTime($certificate->expiresAt),
                'status' => $certificate->status->value,
                'updated_at' => $this->dbTime($certificate->updatedAt),
                'deleted_at' => $certificate->deletedAt === null ? null : $this->dbTime($certificate->deletedAt),
                'version' => $expectedVersion,
            ],
            ['version' => ParameterType::INTEGER],
        );

        if ($affected > 0) {
            $certificate->version = $expectedVersion + 1;
        }

        return $affected > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Certificate
    {
        return new Certificate(
            id: (int) $row['id'],
            title: (string) $row['title'],
            price: new Money((int) $row['price_minor'], (string) $row['currency']),
            expiresAt: $this->date((string) $row['expires_at']),
            status: CertificateStatus::from((string) $row['status']),
            version: (int) $row['version'],
            createdBy: (int) $row['created_by'],
            createdAt: $this->date((string) $row['created_at']),
            updatedAt: $this->date((string) $row['updated_at']),
            deletedAt: $row['deleted_at'] === null ? null : $this->date((string) $row['deleted_at']),
        );
    }

    private function date(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }

    private function dbTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
    }
}
