<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity: one pending invitation per email (partial unique index), revoking older duplicates first.';
    }

    public function up(Schema $schema): void
    {
        // Rows stored before the index can hold several pending invitations for one email: keep the newest
        // (created_at, then id as the tiebreak) and revoke the others, or the index cannot be created.
        $this->addSql("UPDATE identity_invitation SET status = 'revoked' WHERE status = 'pending' AND id NOT IN (SELECT DISTINCT ON (email) id FROM identity_invitation WHERE status = 'pending' ORDER BY email, created_at DESC, id DESC)");
        $this->addSql("CREATE UNIQUE INDEX uniq_identity_invitation_pending_email ON identity_invitation (email) WHERE status = 'pending'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_identity_invitation_pending_email');
    }
}
