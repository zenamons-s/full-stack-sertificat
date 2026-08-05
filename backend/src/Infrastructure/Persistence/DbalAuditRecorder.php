<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\AuditRecorder;
use Doctrine\DBAL\Connection;

final readonly class DbalAuditRecorder implements AuditRecorder
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(int $certificateId, string $actorType, ?int $actorId, string $action, array $changes, string $requestId): void
    {
        $this->connection->insert('certificate_audit', [
            'certificate_id' => $certificateId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'request_id' => $requestId,
        ]);
    }
}
