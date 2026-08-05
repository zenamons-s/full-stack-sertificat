<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class ConflictException extends DomainException
{
    /**
     * @param array<string, mixed>|null $currentState
     */
    public function __construct(string $message, private readonly ?array $currentState = null)
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentState(): ?array
    {
        return $this->currentState;
    }
}
