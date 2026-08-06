<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806010100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add stable marker for demo seed certificates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE certificates ADD COLUMN is_seed BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('CREATE INDEX idx_certificates_seed ON certificates (created_by) WHERE is_seed = TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_certificates_seed');
        $this->addSql('ALTER TABLE certificates DROP COLUMN IF EXISTS is_seed');
    }
}
