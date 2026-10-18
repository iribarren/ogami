<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\EmailAlreadyInUse;
use App\Identity\Domain\Email;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserStatus;
use App\Identity\Infrastructure\Persistence\Doctrine\DoctrineUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(DoctrineUserRepository::class)]
final class DoctrineUserRepositoryTest extends KernelTestCase
{
    private const string ADA_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
    private const string BOB_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';

    private DoctrineUserRepository $users;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->users = $container->get(DoctrineUserRepository::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
    }

    #[Test]
    public function itRoundTripsAUser(): void
    {
        $saved = $this->user(self::ADA_ID, 'ada@example.com', [Role::SoloPlayer, Role::Owner]);
        $saved->disable();
        $saved->changePassword('other-hash');
        $this->users->save($saved);
        $this->entityManager->clear();

        $byId = $this->users->ofId(UserId::fromString(self::ADA_ID));
        self::assertNotNull($byId);
        self::assertEquals($saved, $byId);
        self::assertNotSame($saved, $byId);

        $byEmail = $this->users->ofEmail(Email::fromString('ADA@example.com'));
        self::assertNotNull($byEmail);
        self::assertTrue($byEmail->id()->equals(UserId::fromString(self::ADA_ID)));
    }

    #[Test]
    public function aChangedUserIsUpdated(): void
    {
        $user = $this->user(self::ADA_ID, 'ada@example.com', [Role::SoloPlayer]);
        $this->users->save($user);

        $user->changeRoles([Role::Owner]);
        $user->disable();
        $this->users->save($user);
        $this->entityManager->clear();

        $reloaded = $this->users->ofId(UserId::fromString(self::ADA_ID));
        self::assertNotNull($reloaded);
        self::assertSame([Role::Owner], $reloaded->roles());
        self::assertSame(UserStatus::Disabled, $reloaded->status());
        self::assertEquals(new \DateTimeImmutable('2026-10-18T09:00:00+00:00'), $reloaded->createdAt());
    }

    #[Test]
    public function aLegacyRowWithoutStatusReadsAsActive(): void
    {
        // Rows from before the column existed get the column default.
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO identity_user (id, email, password_hash, roles, created_at) VALUES (?, 'old@example.com', 'hash', '[\"OWNER\"]', '2026-01-02 03:04:05')",
            [self::ADA_ID],
        );

        $user = $this->users->ofId(UserId::fromString(self::ADA_ID));

        self::assertNotNull($user);
        self::assertSame(UserStatus::Active, $user->status());
        self::assertSame([Role::Owner], $user->roles());
        self::assertSame('2026-01-02 03:04:05', $user->createdAt()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function allListsEveryUserOrderedByEmail(): void
    {
        $this->users->save($this->user(self::BOB_ID, 'bob@example.com', [Role::Owner]));
        $this->users->save($this->user(self::ADA_ID, 'ada@example.com', [Role::SoloPlayer]));
        $this->entityManager->clear();

        $emails = array_map(static fn (User $user): string => $user->email()->toString(), $this->users->all());

        self::assertSame(['ada@example.com', 'bob@example.com'], $emails);
    }

    #[Test]
    public function itFindsNothingForUnknownUsers(): void
    {
        self::assertNull($this->users->ofId(UserId::fromString(self::BOB_ID)));
        self::assertNull($this->users->ofId(UserId::fromString('not-a-uuid')));
        self::assertNull($this->users->ofEmail(Email::fromString('nobody@example.com')));
    }

    #[Test]
    public function theDatabaseRejectsADuplicateEmail(): void
    {
        $this->users->save($this->user(self::ADA_ID, 'ada@example.com', [Role::SoloPlayer]));

        $this->expectException(EmailAlreadyInUse::class);

        $this->users->save($this->user(self::BOB_ID, 'ada@example.com', [Role::Owner]));
    }

    /**
     * @param list<Role> $roles
     */
    private function user(string $id, string $email, array $roles): User
    {
        return User::register(UserId::fromString($id), Email::fromString($email), 'hash', $roles, new \DateTimeImmutable('2026-10-18T09:00:00+00:00'));
    }
}
