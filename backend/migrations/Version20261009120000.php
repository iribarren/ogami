<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Play: campaigns hold their tracker values as JSONB (existing campaigns get none).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE play_campaign ADD tracker_values JSONB DEFAULT '{}' NOT NULL");
        // The default only fills existing rows: the mapping has none.
        $this->addSql('ALTER TABLE play_campaign ALTER tracker_values DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE play_campaign DROP tracker_values');
    }
}
