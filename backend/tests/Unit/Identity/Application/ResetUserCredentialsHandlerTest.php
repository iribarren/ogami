<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\PasswordMustNotBeEmpty;
use App\Identity\Application\ResetUserCredentials;
use App\Identity\Application\ResetUserCredentialsHandler;
use App\Identity\Application\UnknownRole;
use App\Identity\Application\UserNotFound;
use App\Identity\Domain\Email;
use App\Identity\Domain\InvalidEmail;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserMustHaveARole;
use App\Identity\Domain\UserStatus;
use App\Tests\Support\Identity\FakePasswordHasher;
use App\Tests\Support\Identity\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResetUserCredentials::class)]
#[CoversClass(ResetUserCredentialsHandler::class)]
#[CoversClass(UserNotFound::class)]
final class ResetUserCredentialsHandlerTest extends TestCase
{
    private InMemoryUserRepository $users;
    private ResetUserCredentialsHandler $handler;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->users->save(User::register(UserId::fromString('user-ada'), Email::fromString('ada@example.com'), 'old-hash', [Role::SoloPlayer], new \DateTimeImmutable('2026-10-18T09:00:00+00:00')));
        $this->handler = new ResetUserCredentialsHandler($this->users, new FakePasswordHasher());
    }

    #[Test]
    public function itSetsThePasswordAndReplacesTheRoles(): void
    {
        ($this->handler)(new ResetUserCredentials(' Ada@Example.com ', 'new-secret', ['GAME_MANAGER', 'OWNER']));

        $user = $this->ada();
        self::assertSame('hashed:new-secret', $user->passwordHash());
        self::assertSame([Role::GameManager, Role::Owner], $user->roles());
        self::assertSame(1, $this->users->count());
    }

    #[Test]
    public function itEnablesADisabledUser(): void
    {
        $this->ada()->disable();

        ($this->handler)(new ResetUserCredentials('ada@example.com', 'new-secret', ['SOLO_PLAYER']));

        self::assertSame(UserStatus::Active, $this->ada()->status());
    }

    #[Test]
    public function itRejectsAnUnknownEmail(): void
    {
        try {
            ($this->handler)(new ResetUserCredentials('bob@example.com', 'new-secret', ['OWNER']));
            self::fail('Credentials were reset for a user that does not exist.');
        } catch (UserNotFound $exception) {
            self::assertStringContainsString('bob@example.com', $exception->getMessage());
        }

        self::assertSame(1, $this->users->count());
    }

    #[Test]
    public function itRejectsAnEmptyPasswordAndChangesNothing(): void
    {
        try {
            ($this->handler)(new ResetUserCredentials('ada@example.com', '  ', ['OWNER']));
            self::fail('An empty password was accepted.');
        } catch (PasswordMustNotBeEmpty) {
            $this->assertUntouched();
        }
    }

    #[Test]
    public function itRejectsAnUnknownRoleAndChangesNothing(): void
    {
        try {
            ($this->handler)(new ResetUserCredentials('ada@example.com', 'new-secret', ['OWNER', 'ADMIN']));
            self::fail('An unknown role was accepted.');
        } catch (UnknownRole $exception) {
            self::assertStringContainsString('"ADMIN"', $exception->getMessage());
            $this->assertUntouched();
        }
    }

    #[Test]
    public function itRejectsAnEmptyRoleListAndChangesNothing(): void
    {
        $this->ada()->disable();

        try {
            ($this->handler)(new ResetUserCredentials('ada@example.com', 'new-secret', []));
            self::fail('A user was left without a role.');
        } catch (UserMustHaveARole) {
            self::assertSame('old-hash', $this->ada()->passwordHash());
            self::assertSame([Role::SoloPlayer], $this->ada()->roles());
            self::assertSame(UserStatus::Disabled, $this->ada()->status());
        }
    }

    #[Test]
    public function itRejectsAnInvalidEmail(): void
    {
        $this->expectException(InvalidEmail::class);

        ($this->handler)(new ResetUserCredentials('not-an-email', 'new-secret', ['OWNER']));
    }

    private function assertUntouched(): void
    {
        self::assertSame('old-hash', $this->ada()->passwordHash());
        self::assertSame([Role::SoloPlayer], $this->ada()->roles());
    }

    private function ada(): User
    {
        $user = $this->users->ofEmail(Email::fromString('ada@example.com'));
        self::assertNotNull($user);

        return $user;
    }
}
