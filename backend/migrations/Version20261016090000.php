<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio: optional release notes on studio_gamesystem_release (null for releases published from a file).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE studio_gamesystem_release ADD notes TEXT DEFAULT NULL');
        // The domain's rule, kept for rows written by anything else: not blank, at most 2,000 characters.
        $this->addSql("ALTER TABLE studio_gamesystem_release ADD CONSTRAINT chk_studio_gamesystem_release_notes CHECK (notes IS NULL OR (char_length(notes) <= 2000 AND notes !~ '^\\s*$'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE studio_gamesystem_release DROP CONSTRAINT chk_studio_gamesystem_release_notes');
        $this->addSql('ALTER TABLE studio_gamesystem_release DROP COLUMN notes');
    }
}
