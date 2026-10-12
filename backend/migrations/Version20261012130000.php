<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Play: campaigns keep their Campaign facts as a JSONB list (existing rows have none).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE play_campaign ADD facts JSONB DEFAULT '[]' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE play_campaign DROP facts');
    }
}
