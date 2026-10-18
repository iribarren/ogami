<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\CreateUser;
use App\Identity\Application\InvalidAccountLink;
use App\Identity\Application\MailUnavailable;
use App\Identity\Application\RequestPasswordReset;
use App\Identity\Application\RequestPasswordResetHandler;
use App\Identity\Application\ResetPassword;
use App\Identity\Application\ResetPasswordHandler;
use App\Identity\Application\SendPasswordReset;
use App\Identity\Application\UserNotFound;
use App\Identity\Domain\AccountToken;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordReset;
use App\Identity\Domain\PasswordResetId;
use App\Identity\Domain\PasswordResetRepository;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserRepository;
use App\Identity\Infrastructure\Mail\SendAccountMailHandler;
use App\Shared\Application\Bus\CommandBus;
use App\Tests\Support\Identity\RecordingMailer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * The password reset commands against the real command bus, Doctrine and mail handler: the mail
 * leaves only after the command's transaction committed (ADR 0034).
 */
#[CoversClass(RequestPasswordResetHandler::class)]
#[CoversClass(ResetPasswordHandler::class)]
final class PasswordResetThroughTheBusesTest extends KernelTestCase
{
    private const string FIRST_RESET_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
    private const string SECOND_RESET_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';
    private const string USER_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d';

    private Connection $connection;
    private CommandBus $bus;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->bus = $container->get(CommandBus::class);
        $this->bus->dispatch(new CreateUser(self::USER_ID, 'ada@example.com', 'old password', ['SOLO_PLAYER']));
    }

    #[Test]
    public function theResetIsStoredWhenTheMailIsSent(): void
    {
        // The reset exists when the mail goes out (that it is committed is shown by the transport failure test).
        $mailer = $this->mailer(new RecordingMailer(fn (): int => \count($this->connection->fetchFirstColumn('SELECT id FROM identity_password_reset'))));

        $this->bus->dispatch(new RequestPasswordReset(self::FIRST_RESET_ID, 'ada@example.com'));

        self::assertCount(1, $mailer->sent);
        self::assertSame([1], $mailer->probes);
        self::assertSame('ada@example.com', $mailer->sent[0]->getTo()[0]->getAddress());
        self::assertMatchesRegularExpression('~/reset-password#token=[A-Za-z0-9_-]{43}~', (string) $mailer->sent[0]->getTextBody());
    }

    #[Test]
    public function noMailLeavesForAnUnknownAccountOrARefusedCommand(): void
    {
        $mailer = $this->mailer(new RecordingMailer());

        $this->bus->dispatch(new RequestPasswordReset(self::FIRST_RESET_ID, 'nobody@example.com'));
        try {
            $this->bus->dispatch(new SendPasswordReset(self::FIRST_RESET_ID, '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4aff'));
            self::fail('A reset was sent to an unknown user.');
        } catch (UserNotFound) {
            self::assertSame([], $mailer->sent);
            self::assertSame([], $this->connection->fetchFirstColumn('SELECT id FROM identity_password_reset'));
        }
    }

    /**
     * Proves the mail is sent after commit: had it been sent inside the command's transaction,
     * its failure would have rolled the reset back.
     */
    #[Test]
    public function aTransportFailureIsMailUnavailableAndTheResetStaysStored(): void
    {
        $this->mailer(new RecordingMailer(failure: new TransportException('smtp down')));

        try {
            $this->bus->dispatch(new SendPasswordReset(self::FIRST_RESET_ID, self::USER_ID));
            self::fail('The mail failure was swallowed.');
        } catch (MailUnavailable $exception) {
            self::assertInstanceOf(TransportException::class, $exception->getPrevious());
        }

        self::assertSame([self::FIRST_RESET_ID], $this->connection->fetchFirstColumn('SELECT id FROM identity_password_reset'));
    }

    #[Test]
    public function aNewRequestDeletesTheOlderResetsOfTheUser(): void
    {
        $this->mailer(new RecordingMailer());

        $this->bus->dispatch(new RequestPasswordReset(self::FIRST_RESET_ID, 'ada@example.com'));
        $this->bus->dispatch(new RequestPasswordReset(self::SECOND_RESET_ID, 'ada@example.com'));

        self::assertSame([self::SECOND_RESET_ID], $this->connection->fetchFirstColumn('SELECT id FROM identity_password_reset'));
    }

    #[Test]
    public function resettingChangesTheStoredHashUsesTheResetAndDeletesTheOthers(): void
    {
        $mailer = $this->mailer(new RecordingMailer());
        $this->bus->dispatch(new RequestPasswordReset(self::FIRST_RESET_ID, 'ada@example.com'));
        preg_match('~#token=([A-Za-z0-9_-]{43})~', (string) $mailer->sent[0]->getTextBody(), $matches);
        $token = $matches[1] ?? self::fail('No link in the email.');
        // Another reset of the same user, such as one left behind by an older request.
        $container = self::getContainer();
        $container->get(PasswordResetRepository::class)->save(PasswordReset::request(
            PasswordResetId::fromString(self::SECOND_RESET_ID),
            UserId::fromString(self::USER_ID),
            AccountToken::fromString('another'),
            new \DateTimeImmutable('2020-01-01T00:00:00+00:00'),
        ));

        $this->bus->dispatch(new ResetPassword($token, 'new password'));
        $container->get(EntityManagerInterface::class)->clear();

        $user = $container->get(UserRepository::class)->ofEmail(Email::fromString('ada@example.com'));
        self::assertNotNull($user);
        self::assertTrue(password_verify('new password', $user->passwordHash()));
        self::assertSame([self::FIRST_RESET_ID], $this->connection->fetchFirstColumn('SELECT id FROM identity_password_reset'));
        self::assertNotNull($container->get(PasswordResetRepository::class)->ofId(PasswordResetId::fromString(self::FIRST_RESET_ID))?->usedAt());

        $this->expectException(InvalidAccountLink::class);

        $this->bus->dispatch(new ResetPassword($token, 'yet another password'));
    }

    /**
     * Replaces the mail transport behind the after-commit handler.
     */
    private function mailer(RecordingMailer $mailer): RecordingMailer
    {
        self::getContainer()->set(SendAccountMailHandler::class, new SendAccountMailHandler($mailer));

        return $mailer;
    }
}
