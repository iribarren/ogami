<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity: one pending invitation per email (partial unique index).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE UNIQUE INDEX uniq_identity_invitation_pending_email ON identity_invitation (email) WHERE status = 'pending'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_identity_invitation_pending_email');
    }
}
