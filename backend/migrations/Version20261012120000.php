<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Play: campaigns keep their NPCs and Threads as JSONB lists (existing rows have none).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE play_campaign ADD npcs JSONB DEFAULT '[]' NOT NULL");
        $this->addSql("ALTER TABLE play_campaign ADD threads JSONB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE play_campaign DROP npcs');
        $this->addSql('ALTER TABLE play_campaign DROP threads');
    }
}
