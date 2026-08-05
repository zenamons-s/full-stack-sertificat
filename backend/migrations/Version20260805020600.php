<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805020600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create certificate audit table and lookup index.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE certificate_audit (
                id             BIGSERIAL PRIMARY KEY,
                certificate_id BIGINT      NOT NULL,
                actor_type     TEXT        NOT NULL,
                actor_id       BIGINT,
                action         TEXT        NOT NULL,
                changes        JSONB       NOT NULL DEFAULT '{}'::jsonb,
                request_id     TEXT,
                created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL);
        $this->addSql('CREATE INDEX idx_audit_cert ON certificate_audit (certificate_id, created_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS certificate_audit');
    }
}
