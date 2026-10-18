<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\AccountToken;
use App\Identity\Domain\Email;
use App\Identity\Domain\Invitation;
use App\Identity\Domain\InvitationId;
use App\Identity\Domain\InvitationMustHaveARole;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserId;
use App\Identity\Infrastructure\Persistence\Doctrine\DoctrineInvitationRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(DoctrineInvitationRepository::class)]
final class DoctrineInvitationRepositoryTest extends KernelTestCase
{
    private const string FIRST_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
    private const string SECOND_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';
    private const string OWNER_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d';

    private DoctrineInvitationRepository $invitations;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->invitations = new DoctrineInvitationRepository($this->entityManager);
    }

    #[Test]
    public function itRoundTripsTheWholeInvitation(): void
    {
        // A non UTC offset and microseconds: the stored instant must survive unchanged.
        $saved = $this->invitation(self::FIRST_ID, 'ada@example.com', 'one', '2026-10-18T11:00:00.123456+02:00');
        $saved->revoke();
        $this->invitations->save($saved);
        $this->entityManager->clear();

        $loaded = $this->invitations->ofId(InvitationId::fromString(self::FIRST_ID));

        self::assertNotNull($loaded);
        self::assertNotSame($saved, $loaded);
        self::assertEquals($saved, $loaded);
        self::assertSame('2026-10-25T09:00:00.123456+00:00', $loaded->expiresAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP'));
    }

    #[Test]
    public function itFindsAnInvitationByTokenHashAndByPendingEmail(): void
    {
        $this->invitations->save($this->invitation(self::FIRST_ID, 'ada@example.com', 'one', '2026-10-18T09:00:00+00:00'));
        $revoked = $this->invitation(self::SECOND_ID, 'bob@example.com', 'two', '2026-10-18T09:00:00+00:00');
        $revoked->revoke();
        $this->invitations->save($revoked);
        $this->entityManager->clear();

        self::assertSame(self::FIRST_ID, $this->invitations->ofTokenHash(AccountToken::fromString('one')->hash())?->id()->toString());
        self::assertSame(self::FIRST_ID, $this->invitations->pendingForEmail(Email::fromString('ADA@example.com'))?->id()->toString());
        self::assertNull($this->invitations->pendingForEmail(Email::fromString('bob@example.com')), 'A revoked invitation is not pending.');
        self::assertNull($this->invitations->ofTokenHash(AccountToken::fromString('unknown')->hash()));
        self::assertNull($this->invitations->ofId(InvitationId::fromString('not-a-uuid')));
    }

    #[Test]
    public function theDatabaseRejectsADuplicateTokenHash(): void
    {
        $this->invitations->save($this->invitation(self::FIRST_ID, 'ada@example.com', 'same', '2026-10-18T09:00:00+00:00'));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->invitations->save($this->invitation(self::SECOND_ID, 'bob@example.com', 'same', '2026-10-18T09:00:00+00:00'));
    }

    #[Test]
    public function aStoredRowWithoutRolesDoesNotLoadSilently(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO identity_invitation (id, email, roles, token_hash, invited_by, created_at, expires_at, status) VALUES (?, 'old@example.com', '[]', 'h', ?, now(), now(), 'pending')",
            [self::FIRST_ID, self::OWNER_ID],
        );

        $loaded = $this->invitations->ofId(InvitationId::fromString(self::FIRST_ID));

        self::assertNotNull($loaded);
        $this->expectException(InvitationMustHaveARole::class);

        $loaded->roles();
    }

    private function invitation(string $id, string $email, string $token, string $now): Invitation
    {
        return Invitation::invite(
            InvitationId::fromString($id),
            Email::fromString($email),
            [Role::GameManager, Role::SoloPlayer],
            AccountToken::fromString($token),
            UserId::fromString(self::OWNER_ID),
            new \DateTimeImmutable($now),
        );
    }
}
