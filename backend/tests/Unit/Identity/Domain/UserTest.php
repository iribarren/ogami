<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\Email;
use App\Identity\Domain\InvalidPasswordHash;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserMustHaveARole;
use App\Identity\Domain\UserStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
#[CoversClass(UserStatus::class)]
#[CoversClass(UserMustHaveARole::class)]
#[CoversClass(InvalidPasswordHash::class)]
final class UserTest extends TestCase
{
    private function createdAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-18T09:00:00+00:00');
    }

    /**
     * @param non-empty-list<Role> $roles
     */
    private function ada(array $roles = [Role::SoloPlayer]): User
    {
        return User::register(UserId::fromString('user-1'), Email::fromString('ada@example.com'), 'hash', $roles, $this->createdAt());
    }

    #[Test]
    public function itRegistersAUser(): void
    {
        $user = User::register(
            UserId::fromString('user-1'),
            Email::fromString('Ada@Example.com'),
            'hashed-secret',
            [Role::SoloPlayer, Role::GameManager],
            $this->createdAt(),
        );

        self::assertTrue($user->id()->equals(UserId::fromString('user-1')));
        self::assertSame('ada@example.com', $user->email()->toString());
        self::assertSame('hashed-secret', $user->passwordHash());
        self::assertSame([Role::SoloPlayer, Role::GameManager], $user->roles());
        self::assertSame(UserStatus::Active, $user->status());
        self::assertFalse($user->isDisabled());
        self::assertEquals($this->createdAt(), $user->createdAt());
    }

    #[Test]
    public function rolesAreIndependent(): void
    {
        $user = User::register(UserId::fromString('user-1'), Email::fromString('ada@example.com'), 'hash', [Role::Owner], $this->createdAt());

        self::assertTrue($user->hasRole(Role::Owner));
        self::assertFalse($user->hasRole(Role::GameManager));
        self::assertFalse($user->hasRole(Role::SoloPlayer));
    }

    #[Test]
    public function duplicateRolesAreKeptOnce(): void
    {
        $user = User::register(UserId::fromString('user-1'), Email::fromString('ada@example.com'), 'hash', [Role::Owner, Role::Owner], $this->createdAt());

        self::assertSame([Role::Owner], $user->roles());
    }

    #[Test]
    public function aUserMustHaveARole(): void
    {
        $this->expectException(UserMustHaveARole::class);

        User::register(UserId::fromString('user-1'), Email::fromString('ada@example.com'), 'hash', [], $this->createdAt());
    }

    #[Test]
    public function aUserMustHaveAPasswordHash(): void
    {
        $this->expectException(InvalidPasswordHash::class);

        User::register(UserId::fromString('user-1'), Email::fromString('ada@example.com'), '', [Role::Owner], $this->createdAt());
    }

    #[Test]
    public function changingRolesReplacesThemAndKeepsEachOnce(): void
    {
        $user = $this->ada([Role::SoloPlayer]);

        $user->changeRoles([Role::GameManager, Role::Owner, Role::GameManager]);

        self::assertSame([Role::GameManager, Role::Owner], $user->roles());
        self::assertFalse($user->hasRole(Role::SoloPlayer));
    }

    #[Test]
    public function changingRolesToNoneIsRefusedAndKeepsTheOldRoles(): void
    {
        $user = $this->ada([Role::SoloPlayer, Role::Owner]);

        try {
            $user->changeRoles([]);
            self::fail('A user was left without a role.');
        } catch (UserMustHaveARole) {
            self::assertSame([Role::SoloPlayer, Role::Owner], $user->roles());
        }
    }

    #[Test]
    public function aUserCanBeDisabledAndEnabledAgain(): void
    {
        $user = $this->ada();

        $user->disable();
        self::assertSame(UserStatus::Disabled, $user->status());
        self::assertTrue($user->isDisabled());

        $user->enable();
        self::assertSame(UserStatus::Active, $user->status());
        self::assertFalse($user->isDisabled());
    }

    #[Test]
    public function disablingAndEnablingAreIdempotent(): void
    {
        $user = $this->ada();

        $user->enable();
        self::assertSame(UserStatus::Active, $user->status());

        $user->disable();
        $user->disable();
        self::assertSame(UserStatus::Disabled, $user->status());
    }

    #[Test]
    public function changingTheStatusOrRolesKeepsTheRestOfTheUser(): void
    {
        $user = $this->ada([Role::SoloPlayer]);

        $user->disable();
        $user->changeRoles([Role::Owner]);

        self::assertSame('user-1', $user->id()->toString());
        self::assertSame('ada@example.com', $user->email()->toString());
        self::assertSame('hash', $user->passwordHash());
        self::assertEquals($this->createdAt(), $user->createdAt());
    }

    #[Test]
    public function aPasswordChangeReplacesTheHash(): void
    {
        $user = $this->ada();

        $user->changePassword('new-hash');

        self::assertSame('new-hash', $user->passwordHash());
    }

    #[Test]
    public function aPasswordChangeNeedsAHash(): void
    {
        $user = $this->ada();

        try {
            $user->changePassword('');
            self::fail('A blank hash was accepted.');
        } catch (InvalidPasswordHash) {
            self::assertSame('hash', $user->passwordHash());
        }
    }

    /**
     * @return iterable<string, array{string, Role}>
     */
    public static function glossaryRoles(): iterable
    {
        yield 'solo player' => ['SOLO_PLAYER', Role::SoloPlayer];
        yield 'game manager' => ['GAME_MANAGER', Role::GameManager];
        yield 'owner' => ['OWNER', Role::Owner];
    }

    #[Test]
    #[DataProvider('glossaryRoles')]
    public function roleValuesMatchTheGlossary(string $value, Role $role): void
    {
        self::assertSame($role, Role::tryFrom($value));
    }
}
