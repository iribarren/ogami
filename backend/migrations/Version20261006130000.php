<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Versioned after Version20261006120000 (a hand-written, later-stamped migration) so that it runs
 * after it.
 */
final class Version20261006130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Play: campaigns carry a version for optimistic locking (a stale save fails instead of losing a session or scene).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE play_campaign ADD version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE play_campaign DROP version');
    }
}
