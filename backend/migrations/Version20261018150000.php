<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity: invitations (token hash unique, time zone aware dates).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE identity_invitation (id UUID NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, token_hash VARCHAR(64) NOT NULL, invited_by UUID NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, status VARCHAR(16) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_identity_invitation_email ON identity_invitation (email)');
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_invitation_token_hash ON identity_invitation (token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity_invitation');
    }
}
