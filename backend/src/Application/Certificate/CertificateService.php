<?php

declare(strict_types=1);

namespace App\Application\Certificate;

use App\Application\Certificate\Command\CreateCertificate;
use App\Application\Certificate\Command\UpdateCertificate;
use App\Application\Certificate\Query\ListCertificatesQuery;
use App\Application\Port\AuditRecorder;
use App\Application\Port\CertificateListCache;
use App\Domain\Certificate\Certificate;
use App\Domain\Certificate\CertificateListFilter;
use App\Domain\Certificate\CertificateRepository;
use App\Domain\Certificate\CertificateStatus;
use App\Domain\Certificate\Money;
use App\Domain\Clock\ClockInterface;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\ValidationException;
use Doctrine\DBAL\Connection;

final readonly class CertificateService
{
    public function __construct(
        private CertificateRepository $certificates,
        private AuditRecorder $audit,
        private CertificateListCache $cache,
        private CertificateResponseMapper $mapper,
        private ClockInterface $clock,
        private Connection $connection,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function list(ListCertificatesQuery $query): array
    {
        $cached = $this->cache->get($query);
        if ($cached !== null) {
            return $cached;
        }

        $status = $query->status === null ? null : CertificateStatus::tryFrom($query->status);
        if ($query->status !== null && $status === null) {
            throw new ValidationException(['status' => ['Допустимые значения: ' . implode(', ', CertificateStatus::values())]]);
        }

        $result = $this->certificates->list(new CertificateListFilter(
            search: $query->search,
            status: $status,
            trashed: $query->trashed,
            page: $query->page,
            perPage: $query->perPage,
            sort: $query->sort,
        ), $query->userId);

        $payload = [
            'data' => array_map(fn (Certificate $certificate): array => $this->mapper->map($certificate), $result->items),
            'meta' => [
                'page' => $result->page,
                'per_page' => $result->perPage,
                'total' => $result->total,
                'total_pages' => $result->totalPages(),
            ],
        ];
        $this->cache->set($query, $payload);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function create(CreateCertificate $command): array
    {
        return $this->connection->transactional(function () use ($command): array {
            $certificate = Certificate::create(
                $command->title,
                new Money($command->priceMinor, $command->currency),
                $command->expiresAt,
                $command->actorId,
                $this->clock,
            );
            $certificate = $this->certificates->add($certificate);

            $this->audit->record($certificate->id ?? 0, 'user', $command->actorId, 'created', [
                'title' => ['old' => null, 'new' => $certificate->title],
                'price_minor' => ['old' => null, 'new' => $certificate->price->minor],
                'currency' => ['old' => null, 'new' => $certificate->price->currency],
                'expires_at' => ['old' => null, 'new' => $this->mapper->map($certificate)['expires_at']],
                'status' => ['old' => null, 'new' => $certificate->status->value],
            ], $command->requestId);
            $this->cache->invalidate();

            return $this->mapper->map($certificate);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $id): array
    {
        return $this->mapper->map($this->certificates->find($id));
    }

    /**
     * @return array<string, mixed>
     */
    public function update(UpdateCertificate $command): array
    {
        return $this->connection->transactional(function () use ($command): array {
            $certificate = $this->certificates->find($command->id);
            $before = $this->mapper->map($certificate);

            if ($command->title !== null) {
                $certificate->rename($command->title);
            }
            if ($command->priceMinor !== null || $command->currency !== null) {
                $certificate->changePrice(new Money(
                    $command->priceMinor ?? $certificate->price->minor,
                    $command->currency ?? $certificate->price->currency,
                ));
            }
            $renewed = false;
            if ($command->expiresAt !== null) {
                $renewed = $certificate->extendValidity($command->expiresAt, $this->clock);
            }
            $certificate->touch($this->clock->now());

            if (!$this->certificates->save($certificate, $command->version)) {
                throw new ConflictException(
                    'Версия записи изменилась; обновите данные перед сохранением',
                    $this->mapper->map($this->certificates->findWithTrashed($command->id)),
                );
            }

            $after = $this->mapper->map($certificate);
            $changes = $this->diff($before, $after, ['title', 'price_minor', 'currency', 'expires_at', 'status']);
            if ($changes !== []) {
                $this->audit->record($certificate->id ?? 0, 'user', $command->actorId, $renewed ? 'renewed' : 'updated', $changes, $command->requestId);
                $this->cache->invalidate();
            }

            return $this->mapper->map($this->certificates->findWithTrashed($command->id));
        });
    }

    public function delete(int $id, int $actorId, string $requestId): void
    {
        $this->connection->transactional(function () use ($id, $actorId, $requestId): void {
            $certificate = $this->certificates->find($id);
            $before = $this->mapper->map($certificate);
            $certificate->softDelete($this->clock);
            $certificate->touch($this->clock->now());
            $this->certificates->save($certificate, $certificate->version);
            $after = $this->mapper->map($certificate);
            $this->audit->record($id, 'user', $actorId, 'deleted', $this->diff($before, $after, ['deleted_at']), $requestId);
            $this->cache->invalidate();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(int $id, int $actorId, string $requestId): array
    {
        return $this->connection->transactional(function () use ($id, $actorId, $requestId): array {
            $certificate = $this->certificates->findWithTrashed($id);
            $before = $this->mapper->map($certificate);
            $certificate->restore($this->clock);
            $certificate->touch($this->clock->now());
            $this->certificates->save($certificate, $certificate->version);
            $after = $this->mapper->map($certificate);
            $this->audit->record($id, 'user', $actorId, 'restored', $this->diff($before, $after, ['deleted_at']), $requestId);
            $this->cache->invalidate();

            return $after;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function audit(int $id, int $page, int $perPage): array
    {
        $this->certificates->findWithTrashed($id);

        $offset = ($page - 1) * $perPage;
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, certificate_id, actor_type, actor_id, action, changes::text AS changes, request_id, created_at FROM certificate_audit WHERE certificate_id = :id ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
            ['id' => $id, 'limit' => $perPage, 'offset' => $offset],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
        );
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM certificate_audit WHERE certificate_id = :id', ['id' => $id]);

        return [
            'data' => array_map(function (array $row): array {
                $changes = is_string($row['changes']) ? json_decode($row['changes'], true, 512, JSON_THROW_ON_ERROR) : [];
                return [
                    'id' => (int) $row['id'],
                    'certificate_id' => (int) $row['certificate_id'],
                    'actor_type' => (string) $row['actor_type'],
                    'actor_id' => $row['actor_id'] === null ? null : (int) $row['actor_id'],
                    'action' => (string) $row['action'],
                    'changes' => is_array($changes) ? $changes : [],
                    'request_id' => $row['request_id'],
                    'created_at' => (new \DateTimeImmutable((string) $row['created_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                ];
            }, $rows),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param list<string> $fields
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function diff(array $before, array $after, array $fields): array
    {
        $diff = [];
        foreach ($fields as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $diff[$field] = ['old' => $before[$field] ?? null, 'new' => $after[$field] ?? null];
            }
        }

        return $diff;
    }
}
