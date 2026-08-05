<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\User\User;
use App\Domain\User\UserRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class DbalUserRepository implements UserRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOne('SELECT id, email, password_hash, created_at FROM users WHERE email = :email', ['email' => $email]);
    }

    public function findById(int $id): ?User
    {
        return $this->findOne('SELECT id, email, password_hash, created_at FROM users WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, int|string> $params
     */
    private function findOne(string $sql, array $params): ?User
    {
        /** @var array{id: int|string, email: string, password_hash: string, created_at: string}|false $row */
        $row = $this->connection->fetchAssociative($sql, $params);
        if ($row === false) {
            return null;
        }

        return new User(
            id: (int) $row['id'],
            email: $row['email'],
            passwordHash: $row['password_hash'],
            createdAt: new DateTimeImmutable($row['created_at']),
        );
    }
}
