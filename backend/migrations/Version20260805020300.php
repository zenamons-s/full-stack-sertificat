<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805020300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id            BIGSERIAL PRIMARY KEY,
                email         CITEXT      NOT NULL UNIQUE,
                password_hash TEXT        NOT NULL,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS users');
    }
}
