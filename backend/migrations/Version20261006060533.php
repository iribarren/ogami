<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006060533 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Play: campaigns (play_campaign, sessions and scenes as JSONB) and their journal entries (play_journal_entry, content as JSONB).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE play_campaign (owner_id UUID NOT NULL, name VARCHAR(100) NOT NULL, game_system_key VARCHAR(64) NOT NULL, release_version INT NOT NULL, game_system_name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, sessions JSONB NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_play_campaign_owner_created ON play_campaign (owner_id, created_at)');
        $this->addSql('CREATE TABLE play_journal_entry (campaign_id UUID NOT NULL, session_number INT NOT NULL, scene_number INT NOT NULL, recorded_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, content JSONB NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_play_journal_entry_campaign_recorded ON play_journal_entry (campaign_id, recorded_at)');
        $this->addSql('CREATE INDEX IDX_1998223CF639F774 ON play_journal_entry (campaign_id)');
        $this->addSql('ALTER TABLE play_journal_entry ADD CONSTRAINT fk_play_journal_entry_campaign FOREIGN KEY (campaign_id) REFERENCES play_campaign (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE play_journal_entry');
        $this->addSql('DROP TABLE play_campaign');
    }
}
