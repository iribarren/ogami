<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\Email;
use App\Identity\Domain\InvalidPasswordHash;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserMustHaveARole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
#[CoversClass(UserMustHaveARole::class)]
#[CoversClass(InvalidPasswordHash::class)]
final class UserTest extends TestCase
{
    #[Test]
    public function itRegistersAUser(): void
    {
        $user = User::register(
            UserId::fromString('user-1'),
            Email::fromString('Ada@Example.com'),
            'hashed-secret',
            [Role::SoloPlayer, Role::GameManager],
        );

        self::assertTrue($user->id()->equals(UserId::fromString('user-1')));
        self::assertSame('ada@example.com', $user->email()->toString());
        self::assertSame('hashed-secret', $user->passwordHash());
        self::assertSame([Role::SoloPlayer, Role::GameManager], $user->roles());
    }

    #[Test]
    public function rolesAreIndependent(): void
    {
        $user = User::register(UserId::fromString('user-1'), Email::fromString('ada@example.com'), 'hash', [Role::Owner]);

        self::assertTrue($user->hasRole(Role::Owner));
        self::assertFalse($user->hasRole(Role::GameManager));
        self::assertFalse($user->hasRole(Role::SoloPlayer));
    }

    #[Test]
    public function duplicateRolesAreKeptOnce(): void
    {
        $user = User::register(UserId::fromString('user-1'), Email::fromString('ada@example.com'), 'hash', [Role::Owner, Role::Owner]);

        self::assertSame([Role::Owner], $user->roles());
    }

    #[Test]
    public function aUserMustHaveARole(): void
    {
        $this->expectException(UserMustHaveARole::class);

        User::register(UserId::fromString('user-1'), Email::fromString('ada@example.com'), 'hash', []);
    }

    #[Test]
    public function aUserMustHaveAPasswordHash(): void
    {
        $this->expectException(InvalidPasswordHash::class);

        User::register(UserId::fromString('user-1'), Email::fromString('ada@example.com'), '', [Role::Owner]);
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
