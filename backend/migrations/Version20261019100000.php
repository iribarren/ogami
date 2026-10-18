<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity: password resets (token hash unique, time zone aware dates, user foreign key).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE identity_password_reset (id UUID NOT NULL, user_id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, used_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_identity_password_reset_user ON identity_password_reset (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_password_reset_token_hash ON identity_password_reset (token_hash)');
        $this->addSql('ALTER TABLE identity_password_reset ADD CONSTRAINT fk_identity_password_reset_user FOREIGN KEY (user_id) REFERENCES identity_user (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity_password_reset');
    }
}
