<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805020100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create PostgreSQL extensions required by certificate search and user email type.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS citext');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP EXTENSION IF EXISTS citext');
        $this->addSql('DROP EXTENSION IF EXISTS pg_trgm');
    }
}
