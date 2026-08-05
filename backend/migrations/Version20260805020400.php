<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805020400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create certificates table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE certificates (
                id          BIGSERIAL PRIMARY KEY,
                title       VARCHAR(255)       NOT NULL,
                price_minor BIGINT             NOT NULL CHECK (price_minor > 0),
                currency    CHAR(3)            NOT NULL DEFAULT 'RUB',
                expires_at  TIMESTAMPTZ        NOT NULL,
                status      certificate_status NOT NULL DEFAULT 'active',
                version     INTEGER            NOT NULL DEFAULT 1,
                created_by  BIGINT             REFERENCES users(id),
                created_at  TIMESTAMPTZ        NOT NULL DEFAULT now(),
                updated_at  TIMESTAMPTZ        NOT NULL DEFAULT now(),
                deleted_at  TIMESTAMPTZ,
                CONSTRAINT title_not_blank CHECK (length(btrim(title)) > 0)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS certificates');
    }
}
