<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio: release notes CHECK with an explicit ASCII-whitespace class instead of the locale-dependent \s.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE studio_gamesystem_release DROP CONSTRAINT chk_studio_gamesystem_release_notes');
        // Always a subset of the domain's rule (GameSystemRelease::publish), which also refuses Unicode-only blanks.
        $this->addSql("ALTER TABLE studio_gamesystem_release ADD CONSTRAINT chk_studio_gamesystem_release_notes CHECK (notes IS NULL OR (char_length(notes) <= 2000 AND notes !~ '^[ \\t\\n\\r\\f\\v]*$'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE studio_gamesystem_release DROP CONSTRAINT chk_studio_gamesystem_release_notes');
        $this->addSql("ALTER TABLE studio_gamesystem_release ADD CONSTRAINT chk_studio_gamesystem_release_notes CHECK (notes IS NULL OR (char_length(notes) <= 2000 AND notes !~ '^\\s*$'))");
    }
}
