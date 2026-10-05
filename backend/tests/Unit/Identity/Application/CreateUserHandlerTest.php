<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\CreateUser;
use App\Identity\Application\CreateUserHandler;
use App\Identity\Application\EmailAlreadyInUse;
use App\Identity\Application\PasswordMustNotBeEmpty;
use App\Identity\Application\UnknownRole;
use App\Identity\Application\UserIdAlreadyInUse;
use App\Identity\Domain\Email;
use App\Identity\Domain\InvalidEmail;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserMustHaveARole;
use App\Tests\Support\Identity\FakePasswordHasher;
use App\Tests\Support\Identity\InMemoryUserRepository;
use App\Tests\Support\Identity\SequentialUserIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateUser::class)]
#[CoversClass(CreateUserHandler::class)]
#[CoversClass(EmailAlreadyInUse::class)]
#[CoversClass(PasswordMustNotBeEmpty::class)]
#[CoversClass(UnknownRole::class)]
#[CoversClass(UserIdAlreadyInUse::class)]
final class CreateUserHandlerTest extends TestCase
{
    private InMemoryUserRepository $users;
    private SequentialUserIdGenerator $ids;
    private CreateUserHandler $handler;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->ids = new SequentialUserIdGenerator();
        $this->handler = new CreateUserHandler($this->users, new FakePasswordHasher());
    }

    #[Test]
    public function itCreatesAUserWithAHashedPassword(): void
    {
        $id = $this->ids->generate()->toString();

        ($this->handler)(new CreateUser($id, ' Ada@Example.com ', 's3cret', ['SOLO_PLAYER', 'OWNER']));

        $user = $this->users->ofId(UserId::fromString($id));
        self::assertNotNull($user);
        self::assertSame('ada@example.com', $user->email()->toString());
        self::assertSame('hashed:s3cret', $user->passwordHash());
        self::assertSame([Role::SoloPlayer, Role::Owner], $user->roles());
    }

    #[Test]
    public function itRejectsAnEmailAlreadyInUse(): void
    {
        ($this->handler)(new CreateUser($this->ids->generate()->toString(), 'ada@example.com', 's3cret', ['SOLO_PLAYER']));

        try {
            ($this->handler)(new CreateUser($this->ids->generate()->toString(), 'ADA@example.com', 'other', ['OWNER']));
            self::fail('A second user with the same email was created.');
        } catch (EmailAlreadyInUse $exception) {
            self::assertStringContainsString('ada@example.com', $exception->getMessage());
        }

        self::assertSame(1, $this->users->count());
        self::assertNotNull($this->users->ofEmail(Email::fromString('ada@example.com')));
    }

    #[Test]
    public function itRejectsAnIdAlreadyInUse(): void
    {
        $id = $this->ids->generate()->toString();
        ($this->handler)(new CreateUser($id, 'ada@example.com', 's3cret', ['SOLO_PLAYER']));

        try {
            ($this->handler)(new CreateUser($id, 'bob@example.com', 'other', ['OWNER']));
            self::fail('A user was created over an existing id.');
        } catch (UserIdAlreadyInUse $exception) {
            self::assertStringContainsString($id, $exception->getMessage());
        }

        $user = $this->users->ofId(UserId::fromString($id));
        self::assertNotNull($user);
        self::assertSame('ada@example.com', $user->email()->toString());
    }

    #[Test]
    public function itRejectsAnEmptyPassword(): void
    {
        $this->expectException(PasswordMustNotBeEmpty::class);

        ($this->handler)(new CreateUser($this->ids->generate()->toString(), 'ada@example.com', '  ', ['SOLO_PLAYER']));
    }

    #[Test]
    public function itRejectsAnUnknownRole(): void
    {
        try {
            ($this->handler)(new CreateUser($this->ids->generate()->toString(), 'ada@example.com', 's3cret', ['SOLO_PLAYER', 'ADMIN']));
            self::fail('A user with an unknown role was created.');
        } catch (UnknownRole $exception) {
            self::assertStringContainsString('"ADMIN"', $exception->getMessage());
        }

        self::assertSame(0, $this->users->count());
    }

    #[Test]
    public function itRejectsAnEmptyRoleList(): void
    {
        $this->expectException(UserMustHaveARole::class);

        ($this->handler)(new CreateUser($this->ids->generate()->toString(), 'ada@example.com', 's3cret', []));
    }

    #[Test]
    public function itRejectsAnInvalidEmail(): void
    {
        $this->expectException(InvalidEmail::class);

        ($this->handler)(new CreateUser($this->ids->generate()->toString(), 'not-an-email', 's3cret', ['OWNER']));
    }
}
