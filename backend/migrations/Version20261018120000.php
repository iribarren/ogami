<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity: user status (active/disabled) and creation date.';
    }

    public function up(Schema $schema): void
    {
        // Existing users stay active (the column default) and get the migration time as their creation date.
        $this->addSql("ALTER TABLE identity_user ADD status VARCHAR(16) DEFAULT 'active' NOT NULL");
        $this->addSql('ALTER TABLE identity_user ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT now() NOT NULL');
        $this->addSql('ALTER TABLE identity_user ALTER created_at DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_user DROP status');
        $this->addSql('ALTER TABLE identity_user DROP created_at');
    }
}
