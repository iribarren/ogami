<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../../migrations/Version20261019090000.php';

/**
 * The pending-email index migration must not fail on rows stored before it existed.
 */
#[CoversNothing]
final class PendingInvitationEmailMigrationTest extends KernelTestCase
{
    #[Test]
    public function itRevokesEveryPendingInvitationButTheNewestPerEmailBeforeCreatingTheIndex(): void
    {
        $connection = self::getContainer()->get(Connection::class);
        // Rolled back with the test transaction, like the rest of the test data.
        $connection->executeStatement('DROP INDEX uniq_identity_invitation_pending_email');
        $this->insert($connection, '0199a1b2-0000-7000-8000-000000000001', 'ada@example.com', 'a1', '2026-10-01T09:00:00+00:00', 'pending');
        $this->insert($connection, '0199a1b2-0000-7000-8000-000000000002', 'ada@example.com', 'a2', '2026-10-03T09:00:00+00:00', 'pending');
        $this->insert($connection, '0199a1b2-0000-7000-8000-000000000003', 'ada@example.com', 'a3', '2026-10-02T09:00:00+00:00', 'pending');
        // Same instant: the id breaks the tie.
        $this->insert($connection, '0199a1b2-0000-7000-8000-000000000004', 'bob@example.com', 'b1', '2026-10-01T09:00:00+00:00', 'pending');
        $this->insert($connection, '0199a1b2-0000-7000-8000-000000000005', 'bob@example.com', 'b2', '2026-10-01T09:00:00+00:00', 'pending');
        // Not pending: left alone, even when newer than the pending one.
        $this->insert($connection, '0199a1b2-0000-7000-8000-000000000006', 'cy@example.com', 'c1', '2026-10-01T09:00:00+00:00', 'pending');
        $this->insert($connection, '0199a1b2-0000-7000-8000-000000000007', 'cy@example.com', 'c2', '2026-10-05T09:00:00+00:00', 'accepted');

        /** @var class-string<AbstractMigration> $class */
        $class = 'DoctrineMigrations\\Version20261019090000';
        $migration = new $class($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }

        self::assertSame(
            [
                '0199a1b2-0000-7000-8000-000000000001' => 'revoked',
                '0199a1b2-0000-7000-8000-000000000002' => 'pending',
                '0199a1b2-0000-7000-8000-000000000003' => 'revoked',
                '0199a1b2-0000-7000-8000-000000000004' => 'revoked',
                '0199a1b2-0000-7000-8000-000000000005' => 'pending',
                '0199a1b2-0000-7000-8000-000000000006' => 'pending',
                '0199a1b2-0000-7000-8000-000000000007' => 'accepted',
            ],
            $connection->fetchAllKeyValue('SELECT id, status FROM identity_invitation ORDER BY id'),
        );
        self::assertSame(1, $connection->fetchOne("SELECT count(*) FROM pg_indexes WHERE indexname = 'uniq_identity_invitation_pending_email'"));
    }

    private function insert(Connection $connection, string $id, string $email, string $hash, string $createdAt, string $status): void
    {
        $connection->executeStatement(
            "INSERT INTO identity_invitation (id, email, roles, token_hash, invited_by, created_at, expires_at, status) VALUES (?, ?, '[\"SOLO_PLAYER\"]', ?, '0199a1b2-0000-7000-8000-0000000000ff', ?, ?, ?)",
            [$id, $email, $hash, $createdAt, $createdAt, $status],
        );
    }
}
