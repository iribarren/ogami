<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\Email;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Identity\Domain\UserStatus;
use App\Identity\Infrastructure\Console\CreateUserConsoleCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CreateUserConsoleCommand::class)]
final class CreateUserConsoleCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $this->tester = new CommandTester($application->find('app:user:create'));
    }

    #[Test]
    public function itCreatesAUserAndPrintsItsId(): void
    {
        $exitCode = $this->tester->execute(
            ['email' => 'Ada@Example.com', '--role' => ['SOLO_PLAYER', 'OWNER'], '--password' => 's3cret'],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        $user = self::getContainer()->get(UserRepository::class)->ofEmail(Email::fromString('ada@example.com'));
        self::assertNotNull($user);
        self::assertSame([Role::SoloPlayer, Role::Owner], $user->roles());
        self::assertStringContainsString($user->id()->toString(), $this->tester->getDisplay());
        self::assertMatchesRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}/', $user->id()->toString());
    }

    #[Test]
    public function itAsksForThePasswordTwiceWhenInteractive(): void
    {
        $this->tester->setInputs(['s3cret', 's3cret']);

        $exitCode = $this->tester->execute(['email' => 'ada@example.com', '--role' => ['GAME_MANAGER']]);

        self::assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        self::assertNotNull(self::getContainer()->get(UserRepository::class)->ofEmail(Email::fromString('ada@example.com')));
    }

    #[Test]
    public function itFailsWhenTheTypedPasswordsDiffer(): void
    {
        $this->tester->setInputs(['s3cret', 'other']);

        $exitCode = $this->tester->execute(['email' => 'ada@example.com', '--role' => ['GAME_MANAGER']]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('do not match', $this->tester->getDisplay());
    }

    #[Test]
    public function itRejectsADuplicateEmail(): void
    {
        $input = ['email' => 'ada@example.com', '--role' => ['SOLO_PLAYER'], '--password' => 's3cret'];
        $this->tester->execute($input, ['interactive' => false]);

        $exitCode = $this->tester->execute($input, ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('already exists', $this->tester->getDisplay());
    }

    #[Test]
    public function itLeavesAnExistingUserAloneWhenAskedToCreateItOnlyIfMissing(): void
    {
        $this->tester->execute(
            ['email' => 'ada@example.com', '--role' => ['SOLO_PLAYER'], '--password' => 's3cret'],
            ['interactive' => false],
        );
        $existing = self::getContainer()->get(UserRepository::class)->ofEmail(Email::fromString('ada@example.com'));
        self::assertNotNull($existing);

        $exitCode = $this->tester->execute(
            ['email' => ' Ada@Example.com', '--role' => ['OWNER'], '--password' => 'other', '--if-missing' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        self::assertStringContainsString('already exists', $this->tester->getDisplay());
        $user = self::getContainer()->get(UserRepository::class)->ofEmail(Email::fromString('ada@example.com'));
        self::assertNotNull($user);
        self::assertTrue($existing->id()->equals($user->id()));
        self::assertSame([Role::SoloPlayer], $user->roles());
    }

    #[Test]
    public function itCreatesAMissingUserWhenAskedToCreateItOnlyIfMissing(): void
    {
        $exitCode = $this->tester->execute(
            ['email' => 'ada@example.com', '--role' => ['OWNER'], '--password' => 's3cret', '--if-missing' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        self::assertStringContainsString('Created user', $this->tester->getDisplay());
        self::assertNotNull(self::getContainer()->get(UserRepository::class)->ofEmail(Email::fromString('ada@example.com')));
    }

    #[Test]
    public function itResetsAnExistingUserWhenAskedToResetExisting(): void
    {
        $this->tester->execute(
            ['email' => 'ada@example.com', '--role' => ['SOLO_PLAYER'], '--password' => 's3cret'],
            ['interactive' => false],
        );
        $existing = $this->user('ada@example.com');

        $exitCode = $this->tester->execute(
            ['email' => ' Ada@Example.com', '--role' => ['GAME_MANAGER', 'OWNER'], '--password' => 'new-secret', '--reset-existing' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        self::assertStringContainsString('Reset user ada@example.com', $this->tester->getDisplay());
        $user = $this->user('ada@example.com');
        self::assertTrue($existing->id()->equals($user->id()));
        self::assertSame([Role::GameManager, Role::Owner], $user->roles());
        self::assertTrue(password_verify('new-secret', $user->passwordHash()));
        self::assertFalse(password_verify('s3cret', $user->passwordHash()));
    }

    #[Test]
    public function itEnablesADisabledUserWhenAskedToResetExisting(): void
    {
        $this->tester->execute(
            ['email' => 'ada@example.com', '--role' => ['SOLO_PLAYER'], '--password' => 's3cret'],
            ['interactive' => false],
        );
        $users = self::getContainer()->get(UserRepository::class);
        $ada = $this->user('ada@example.com');
        $ada->disable();
        $users->save($ada);

        $exitCode = $this->tester->execute(
            ['email' => 'ada@example.com', '--role' => ['SOLO_PLAYER'], '--password' => 's3cret', '--reset-existing' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        self::assertSame(UserStatus::Active, $this->user('ada@example.com')->status());
    }

    #[Test]
    public function itCreatesAMissingUserWhenAskedToResetExisting(): void
    {
        $exitCode = $this->tester->execute(
            ['email' => 'ada@example.com', '--role' => ['OWNER'], '--password' => 's3cret', '--reset-existing' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        self::assertStringContainsString('Created user', $this->tester->getDisplay());
        self::assertSame([Role::Owner], $this->user('ada@example.com')->roles());
    }

    #[Test]
    public function itLeavesAnExistingUserUntouchedWhenTheResetInputIsInvalid(): void
    {
        $this->tester->execute(
            ['email' => 'ada@example.com', '--role' => ['SOLO_PLAYER'], '--password' => 's3cret'],
            ['interactive' => false],
        );

        $exitCode = $this->tester->execute(
            ['email' => 'ada@example.com', '--role' => ['OWNER', 'ADMIN'], '--password' => 'new-secret', '--reset-existing' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('"ADMIN" is not a role', $this->tester->getDisplay());
        $user = $this->user('ada@example.com');
        self::assertSame([Role::SoloPlayer], $user->roles());
        self::assertTrue(password_verify('s3cret', $user->passwordHash()));
    }

    #[Test]
    public function itRejectsResetExistingTogetherWithIfMissing(): void
    {
        $this->tester->execute(
            ['email' => 'ada@example.com', '--role' => ['SOLO_PLAYER'], '--password' => 's3cret'],
            ['interactive' => false],
        );

        $exitCode = $this->tester->execute(
            ['email' => 'ada@example.com', '--role' => ['OWNER'], '--password' => 'other', '--reset-existing' => true, '--if-missing' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--reset-existing', $this->tester->getDisplay());
        $user = $this->user('ada@example.com');
        self::assertSame([Role::SoloPlayer], $user->roles());
        self::assertTrue(password_verify('s3cret', $user->passwordHash()));
    }

    #[Test]
    public function itRejectsResetExistingTogetherWithIfMissingEvenForAMissingUser(): void
    {
        $exitCode = $this->tester->execute(
            ['email' => 'ada@example.com', '--role' => ['OWNER'], '--password' => 's3cret', '--reset-existing' => true, '--if-missing' => true],
            ['interactive' => false],
        );

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertNull(self::getContainer()->get(UserRepository::class)->ofEmail(Email::fromString('ada@example.com')));
    }

    #[Test]
    public function itRejectsAnUnknownRole(): void
    {
        $exitCode = $this->tester->execute(
            ['email' => 'ada@example.com', '--role' => ['ADMIN'], '--password' => 's3cret'],
            ['interactive' => false],
        );

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('"ADMIN" is not a role', $this->tester->getDisplay());
    }

    #[Test]
    public function itRequiresARole(): void
    {
        $exitCode = $this->tester->execute(['email' => 'ada@example.com', '--password' => 's3cret'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--role', $this->tester->getDisplay());
    }

    #[Test]
    public function itRequiresAPasswordWhenNotInteractive(): void
    {
        $exitCode = $this->tester->execute(['email' => 'ada@example.com', '--role' => ['OWNER']], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--password', $this->tester->getDisplay());
    }

    private function user(string $email): User
    {
        $user = self::getContainer()->get(UserRepository::class)->ofEmail(Email::fromString($email));
        self::assertNotNull($user);

        return $user;
    }
}
