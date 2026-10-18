<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\ChangeUserRoles;
use App\Identity\Application\ChangeUserRolesHandler;
use App\Identity\Application\DisableUser;
use App\Identity\Application\DisableUserHandler;
use App\Identity\Application\EnableUser;
use App\Identity\Application\EnableUserHandler;
use App\Identity\Application\LastActiveOwner;
use App\Identity\Application\ListUsers;
use App\Identity\Application\ListUsersHandler;
use App\Identity\Application\OwnAccountProtected;
use App\Identity\Application\UnknownRole;
use App\Identity\Application\UserLoader;
use App\Identity\Application\UserNotFound;
use App\Identity\Application\UserView;
use App\Identity\Domain\Email;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserMustHaveARole;
use App\Identity\Domain\UserStatus;
use App\Tests\Support\Identity\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListUsers::class)]
#[CoversClass(ListUsersHandler::class)]
#[CoversClass(ChangeUserRoles::class)]
#[CoversClass(ChangeUserRolesHandler::class)]
#[CoversClass(DisableUser::class)]
#[CoversClass(DisableUserHandler::class)]
#[CoversClass(EnableUser::class)]
#[CoversClass(EnableUserHandler::class)]
#[CoversClass(UserLoader::class)]
#[CoversClass(UserView::class)]
#[CoversClass(OwnAccountProtected::class)]
#[CoversClass(LastActiveOwner::class)]
#[CoversClass(UserNotFound::class)]
final class UserManagementHandlersTest extends TestCase
{
    private const string OWNER = 'owner-1';
    private const string ADA = 'user-ada';

    private InMemoryUserRepository $users;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->add(self::OWNER, 'zed@example.com', [Role::Owner]);
        $this->add(self::ADA, 'ada@example.com', [Role::SoloPlayer]);
    }

    #[Test]
    public function itListsUsersByEmailWithStatusAndCreationDate(): void
    {
        $this->user(self::ADA)->disable();

        $views = (new ListUsersHandler($this->users))(new ListUsers());

        self::assertSame(['ada@example.com', 'zed@example.com'], array_map(static fn (UserView $view): string => $view->email, $views));
        self::assertSame(['disabled', 'active'], array_map(static fn (UserView $view): string => $view->status, $views));
        self::assertSame(['SOLO_PLAYER'], $views[0]->roles);
        self::assertEquals(new \DateTimeImmutable('2026-10-18T09:00:00+00:00'), $views[0]->createdAt);
    }

    #[Test]
    public function itChangesRoles(): void
    {
        $this->changeRoles(self::OWNER, self::ADA, ['GAME_MANAGER', 'OWNER', 'GAME_MANAGER']);

        self::assertSame([Role::GameManager, Role::Owner], $this->user(self::ADA)->roles());
    }

    #[Test]
    public function anOwnerCanChangeTheirOwnRolesWhileKeepingOwner(): void
    {
        $this->changeRoles(self::OWNER, self::OWNER, ['OWNER', 'GAME_MANAGER']);

        self::assertSame([Role::Owner, Role::GameManager], $this->user(self::OWNER)->roles());
    }

    #[Test]
    public function itRefusesToDropTheActorsOwnOwnerRole(): void
    {
        $this->expectException(OwnAccountProtected::class);

        try {
            $this->changeRoles(self::OWNER, self::OWNER, ['SOLO_PLAYER']);
        } finally {
            self::assertSame([Role::Owner], $this->user(self::OWNER)->roles());
        }
    }

    #[Test]
    public function itRefusesAnUnknownRoleAndChangesNothing(): void
    {
        try {
            $this->changeRoles(self::OWNER, self::ADA, ['GAME_MANAGER', 'WIZARD']);
            self::fail('An unknown role was accepted.');
        } catch (UnknownRole $exception) {
            self::assertStringContainsString('"WIZARD"', $exception->getMessage());
            self::assertSame([Role::SoloPlayer], $this->user(self::ADA)->roles());
        }
    }

    #[Test]
    public function itRefusesAnEmptyRoleListAndChangesNothing(): void
    {
        foreach ([self::ADA, self::OWNER] as $target) {
            try {
                $this->changeRoles(self::OWNER, $target, []);
                self::fail('A user was left without a role.');
            } catch (UserMustHaveARole) {
                self::assertNotSame([], $this->user($target)->roles());
            }
        }
    }

    #[Test]
    public function itDisablesAndEnablesAUser(): void
    {
        (new DisableUserHandler($this->users, new UserLoader($this->users)))(new DisableUser(self::OWNER, self::ADA));
        self::assertSame(UserStatus::Disabled, $this->user(self::ADA)->status());

        (new EnableUserHandler($this->users, new UserLoader($this->users)))(new EnableUser(self::ADA));
        self::assertSame(UserStatus::Active, $this->user(self::ADA)->status());
    }

    #[Test]
    public function itRefusesToDisableTheActorsOwnAccount(): void
    {
        try {
            (new DisableUserHandler($this->users, new UserLoader($this->users)))(new DisableUser(self::OWNER, self::OWNER));
            self::fail('The actor disabled themselves.');
        } catch (OwnAccountProtected) {
            self::assertSame(UserStatus::Active, $this->user(self::OWNER)->status());
        }
    }

    #[Test]
    public function itRefusesToDisableTheOnlyActiveOwner(): void
    {
        try {
            $this->disable(self::ADA, self::OWNER);
            self::fail('The only active owner was disabled.');
        } catch (LastActiveOwner) {
            self::assertSame(UserStatus::Active, $this->user(self::OWNER)->status());
        }
    }

    #[Test]
    public function itRefusesToDemoteTheOnlyActiveOwner(): void
    {
        try {
            $this->changeRoles(self::ADA, self::OWNER, ['GAME_MANAGER']);
            self::fail('The only active owner was demoted.');
        } catch (LastActiveOwner) {
            self::assertSame([Role::Owner], $this->user(self::OWNER)->roles());
        }
    }

    #[Test]
    public function anOwnerWhoseColleagueIsDisabledIsStillTheLastActiveOwner(): void
    {
        $this->add('owner-2', 'other@example.com', [Role::Owner]);
        $this->user('owner-2')->disable();

        $this->expectException(LastActiveOwner::class);

        $this->disable(self::ADA, self::OWNER);
    }

    #[Test]
    public function itAllowsRemovingAnOwnerWhenAnotherOwnerIsActive(): void
    {
        $this->add('owner-2', 'other@example.com', [Role::Owner]);

        $this->changeRoles(self::ADA, 'owner-2', ['SOLO_PLAYER']);
        self::assertSame([Role::SoloPlayer], $this->user('owner-2')->roles());

        $this->user('owner-2')->changeRoles([Role::Owner]);
        $this->disable(self::ADA, self::OWNER);
        self::assertSame(UserStatus::Disabled, $this->user(self::OWNER)->status());
    }

    #[Test]
    public function itLocksTheOwnersOnlyWhenOwnershipIsAtStake(): void
    {
        $this->disable(self::OWNER, self::ADA);
        $this->changeRoles(self::OWNER, self::ADA, ['GAME_MANAGER']);
        $this->changeRoles(self::ADA, self::OWNER, ['OWNER', 'GAME_MANAGER']);
        self::assertSame(0, $this->users->ownerLocks);

        $this->add('owner-2', 'other@example.com', [Role::Owner]);
        $this->disable(self::ADA, 'owner-2');
        self::assertSame(1, $this->users->ownerLocks);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownIds(): iterable
    {
        yield 'unknown uuid' => ['0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'];
        yield 'not a uuid' => ['nope'];
        yield 'blank' => [' '];
    }

    #[Test]
    #[DataProvider('unknownIds')]
    public function anUnknownOrMalformedUserIsNotFound(string $id): void
    {
        $loader = new UserLoader($this->users);
        $commands = [
            fn () => (new ChangeUserRolesHandler($this->users, $loader))(new ChangeUserRoles(self::OWNER, $id, ['OWNER'])),
            fn () => (new DisableUserHandler($this->users, $loader))(new DisableUser(self::OWNER, $id)),
            fn () => (new EnableUserHandler($this->users, $loader))(new EnableUser($id)),
        ];

        foreach ($commands as $command) {
            try {
                $command();
                self::fail('A command on an unknown user succeeded.');
            } catch (UserNotFound $exception) {
                self::assertStringContainsString($id, $exception->getMessage());
            }
        }
    }

    private function disable(string $actorId, string $userId): void
    {
        (new DisableUserHandler($this->users, new UserLoader($this->users)))(new DisableUser($actorId, $userId));
    }

    /**
     * @param list<string> $roles
     */
    private function changeRoles(string $actorId, string $userId, array $roles): void
    {
        (new ChangeUserRolesHandler($this->users, new UserLoader($this->users)))(new ChangeUserRoles($actorId, $userId, $roles));
    }

    /**
     * @param non-empty-list<Role> $roles
     */
    private function add(string $id, string $email, array $roles): void
    {
        $this->users->save(User::register(UserId::fromString($id), Email::fromString($email), 'hash', $roles, new \DateTimeImmutable('2026-10-18T09:00:00+00:00')));
    }

    private function user(string $id): User
    {
        $user = $this->users->ofId(UserId::fromString($id));
        self::assertNotNull($user);

        return $user;
    }
}
