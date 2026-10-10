<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Play: campaigns keep the key of the Flow they play (existing campaigns play freely).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE play_campaign ADD flow_key VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE play_campaign DROP flow_key');
    }
}
