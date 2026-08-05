<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805020200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create certificate_status enum.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                CREATE TYPE certificate_status AS ENUM ('active','expired','redeemed','cancelled');
            EXCEPTION
                WHEN duplicate_object THEN NULL;
            END
            $$;
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TYPE IF EXISTS certificate_status');
    }
}
