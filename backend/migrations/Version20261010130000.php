<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Play: campaigns keep their FlowRun and journal entries the Flow step that recorded them, as JSONB (existing rows have none).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE play_campaign ADD flow_run JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE play_journal_entry ADD flow_step JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE play_campaign DROP flow_run');
        $this->addSql('ALTER TABLE play_journal_entry DROP flow_step');
    }
}
