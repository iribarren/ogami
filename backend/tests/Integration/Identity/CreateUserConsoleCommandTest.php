<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\Email;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
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
}
