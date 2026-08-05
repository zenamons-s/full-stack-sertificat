<?php

declare(strict_types=1);

namespace App\Domain\Exception;

final class ValidationException extends DomainException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(private readonly array $errors, string $message = 'Request payload is invalid')
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
