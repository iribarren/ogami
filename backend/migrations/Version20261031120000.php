<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Play: campaigns keep their Places as a JSONB list (existing rows have none).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE play_campaign ADD places JSONB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE play_campaign DROP places');
    }
}
