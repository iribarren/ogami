<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005213902 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio: published GameSystem releases (studio_gamesystem_release), content as JSONB.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE studio_gamesystem_release (game_system_key VARCHAR(64) NOT NULL, version INT NOT NULL, schema_version INT NOT NULL, content JSONB NOT NULL, content_hash CHAR(64) NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_studio_gamesystem_release_key_version ON studio_gamesystem_release (game_system_key, version)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE studio_gamesystem_release');
    }
}
