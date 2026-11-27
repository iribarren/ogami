<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio: the card images a GameSystem release pins, written once at publish (ADR 0053).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE studio_release_card_image_pin (release_id UUID NOT NULL, slot VARCHAR(16) NOT NULL, sha256 CHAR(64) NOT NULL, PRIMARY KEY (release_id, slot))');
        $this->addSql('CREATE INDEX idx_studio_release_card_image_pin_sha256 ON studio_release_card_image_pin (sha256)');
        // Releases are never deleted, so the key needs no cascade; a pinned image cannot be deleted either.
        $this->addSql('ALTER TABLE studio_release_card_image_pin ADD CONSTRAINT fk_studio_release_card_image_pin_release FOREIGN KEY (release_id) REFERENCES studio_gamesystem_release (id)');
        $this->addSql('ALTER TABLE studio_release_card_image_pin ADD CONSTRAINT fk_studio_release_card_image_pin_image FOREIGN KEY (sha256) REFERENCES studio_image (sha256)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE studio_release_card_image_pin');
    }
}
