<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805020500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create certificate partial indexes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_cert_expiry_sweep ON certificates (expires_at)
                WHERE status = 'active' AND deleted_at IS NULL
            SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_cert_list ON certificates (created_at DESC, id DESC)
                WHERE deleted_at IS NULL
            SQL);
        $this->addSql("CREATE INDEX idx_cert_status ON certificates (status) WHERE deleted_at IS NULL");
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_cert_title_trgm ON certificates USING gin (title gin_trgm_ops)
                WHERE deleted_at IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_cert_title_trgm');
        $this->addSql('DROP INDEX IF EXISTS idx_cert_status');
        $this->addSql('DROP INDEX IF EXISTS idx_cert_list');
        $this->addSql('DROP INDEX IF EXISTS idx_cert_expiry_sweep');
    }
}
