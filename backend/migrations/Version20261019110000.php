<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Play: one oracle table entry per random event question (partial unique index on content forEntry).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE UNIQUE INDEX uniq_play_journal_entry_random_event ON play_journal_entry ((content ->> 'forEntry')) WHERE content ->> 'forEntry' IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_play_journal_entry_random_event');
    }
}
