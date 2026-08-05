<?php

declare(strict_types=1);

namespace App\Application\Port;

interface AuditRecorder
{
    /**
     * @param array<string, array{old: mixed, new: mixed}> $changes
     */
    public function record(int $certificateId, string $actorType, ?int $actorId, string $action, array $changes, string $requestId): void;
}
