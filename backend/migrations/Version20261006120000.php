<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Written by hand: the schema tool reads every timestamptz column back as the microsecond type
 * (doctrine.yaml mapping_types), so it cannot see a precision change.
 */
final class Version20261006120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Play: campaign creation and journal entry times keep their microseconds (TIMESTAMP(6) WITH TIME ZONE).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE play_campaign ALTER created_at TYPE TIMESTAMP(6) WITH TIME ZONE');
        $this->addSql('ALTER TABLE play_journal_entry ALTER recorded_at TYPE TIMESTAMP(6) WITH TIME ZONE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE play_journal_entry ALTER recorded_at TYPE TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('ALTER TABLE play_campaign ALTER created_at TYPE TIMESTAMP(0) WITH TIME ZONE');
    }
}
