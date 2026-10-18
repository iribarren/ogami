<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\ChangeUserRoles;
use App\Identity\Application\ChangeUserRolesHandler;
use App\Identity\Application\CreateUser;
use App\Identity\Application\DisableUser;
use App\Identity\Application\DisableUserHandler;
use App\Identity\Application\LastActiveOwner;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserRepository;
use App\Identity\Domain\UserStatus;
use App\Identity\Infrastructure\Persistence\Doctrine\DoctrineUserRepository;
use App\Shared\Application\Bus\CommandBus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The last-active-owner guard against the real command bus and database, where the
 * rows are locked (ADR 0034). The Behat scenarios cover it on in-memory fakes only.
 */
#[CoversClass(DisableUserHandler::class)]
#[CoversClass(ChangeUserRolesHandler::class)]
#[CoversClass(DoctrineUserRepository::class)]
final class LastActiveOwnerThroughCommandBusTest extends KernelTestCase
{
    private const string OWNER_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
    private const string OTHER_OWNER_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';
    private const string MANAGER_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d';

    private CommandBus $bus;
    private UserRepository $users;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->bus = $container->get(CommandBus::class);
        $this->users = $container->get(UserRepository::class);
        $this->createUser(self::OWNER_ID, 'owner@example.com', 'OWNER');
        $this->createUser(self::MANAGER_ID, 'manager@example.com', 'GAME_MANAGER');
    }

    #[Test]
    public function theOnlyActiveOwnerCannotBeDisabled(): void
    {
        $this->expectException(LastActiveOwner::class);

        try {
            $this->bus->dispatch(new DisableUser(self::MANAGER_ID, self::OWNER_ID));
        } finally {
            $this->assertOwnerUntouched();
        }
    }

    #[Test]
    public function theOnlyActiveOwnerCannotLoseTheOwnerRole(): void
    {
        $this->expectException(LastActiveOwner::class);

        try {
            $this->bus->dispatch(new ChangeUserRoles(self::MANAGER_ID, self::OWNER_ID, ['SOLO_PLAYER']));
        } finally {
            $this->assertOwnerUntouched();
        }
    }

    #[Test]
    public function anOwnerCanDisableAnotherOwnerWhileOneStaysActive(): void
    {
        $this->createUser(self::OTHER_OWNER_ID, 'other@example.com', 'OWNER');

        $this->bus->dispatch(new DisableUser(self::OWNER_ID, self::OTHER_OWNER_ID));

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(UserStatus::Disabled, $this->users->ofId(UserId::fromString(self::OTHER_OWNER_ID))?->status());
        self::assertSame(UserStatus::Active, $this->users->ofId(UserId::fromString(self::OWNER_ID))?->status());
    }

    private function createUser(string $id, string $email, string $role): void
    {
        $this->bus->dispatch(new CreateUser($id, $email, 'secret123', [$role]));
    }

    private function assertOwnerUntouched(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $owner = $this->users->ofId(UserId::fromString(self::OWNER_ID));

        self::assertNotNull($owner);
        self::assertSame(UserStatus::Active, $owner->status());
        self::assertSame([Role::Owner], $owner->roles());
    }
}
