<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005090401 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity: users who can sign in (identity_user).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE identity_user (email VARCHAR(180) NOT NULL, password_hash VARCHAR(255) NOT NULL, roles JSON NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_user_email ON identity_user (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity_user');
    }
}
