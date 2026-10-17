<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio: GameSystem kind (game-system or library-pack) and the library imports of a draft (ADR 0033).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE studio_gamesystem ADD kind VARCHAR(32) DEFAULT 'game-system' NOT NULL");
        $this->addSql("ALTER TABLE studio_gamesystem ADD library_imports JSONB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE studio_gamesystem DROP library_imports');
        $this->addSql('ALTER TABLE studio_gamesystem DROP kind');
    }
}
