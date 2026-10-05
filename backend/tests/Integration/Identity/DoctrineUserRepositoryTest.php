<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\EmailAlreadyInUse;
use App\Identity\Domain\Email;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
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
        $this->users->save($this->user(self::ADA_ID, 'ada@example.com', [Role::SoloPlayer, Role::Owner]));
        $this->entityManager->clear();

        $byId = $this->users->ofId(UserId::fromString(self::ADA_ID));
        self::assertNotNull($byId);
        self::assertSame('ada@example.com', $byId->email()->toString());
        self::assertSame('hash', $byId->passwordHash());
        self::assertSame([Role::SoloPlayer, Role::Owner], $byId->roles());

        $byEmail = $this->users->ofEmail(Email::fromString('ADA@example.com'));
        self::assertNotNull($byEmail);
        self::assertTrue($byEmail->id()->equals(UserId::fromString(self::ADA_ID)));
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
        return User::register(UserId::fromString($id), Email::fromString($email), 'hash', $roles);
    }
}
