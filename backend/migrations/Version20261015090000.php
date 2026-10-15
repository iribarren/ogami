<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio: GameSystem drafts (studio_gamesystem), the draft document as JSONB with an optimistic lock.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE studio_gamesystem (game_system_key VARCHAR(64) NOT NULL, owner_id VARCHAR(255) NOT NULL, document JSONB NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, version INT DEFAULT 1 NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_studio_gamesystem_key ON studio_gamesystem (game_system_key)');
        $this->addSql('CREATE INDEX idx_studio_gamesystem_owner ON studio_gamesystem (owner_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE studio_gamesystem');
    }
}
