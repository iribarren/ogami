<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio: card images as bytea keyed by their sha256, and the GameSystem drafts\' image pins (ADR 0053).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE studio_image (sha256 CHAR(64) NOT NULL, media_type VARCHAR(16) NOT NULL, width INT NOT NULL, height INT NOT NULL, byte_size INT NOT NULL, data BYTEA NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (sha256))');
        $this->addSql('CREATE TABLE studio_card_image_pin (game_system_id UUID NOT NULL, slot VARCHAR(16) NOT NULL, sha256 CHAR(64) NOT NULL, PRIMARY KEY (game_system_id, slot))');
        $this->addSql('CREATE INDEX idx_studio_card_image_pin_sha256 ON studio_card_image_pin (sha256)');
        $this->addSql('ALTER TABLE studio_card_image_pin ADD CONSTRAINT fk_studio_card_image_pin_gamesystem FOREIGN KEY (game_system_id) REFERENCES studio_gamesystem (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE studio_card_image_pin ADD CONSTRAINT fk_studio_card_image_pin_image FOREIGN KEY (sha256) REFERENCES studio_image (sha256)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE studio_card_image_pin');
        $this->addSql('DROP TABLE studio_image');
    }
}
