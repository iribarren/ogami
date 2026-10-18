<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Studio: the library imports of a draft, kept on its release as provenance outside the hash (ADR 0033).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE studio_gamesystem_release ADD library_sources JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE studio_gamesystem_release DROP library_sources');
    }
}
