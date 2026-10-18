<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\CreateUser;
use App\Identity\Application\EmailAlreadyInUse;
use App\Identity\Application\InviteUser;
use App\Identity\Application\InviteUserHandler;
use App\Identity\Application\MailUnavailable;
use App\Identity\Domain\Email;
use App\Identity\Domain\InvitationRepository;
use App\Identity\Infrastructure\Mail\SendAccountMailHandler;
use App\Shared\Application\Bus\CommandBus;
use App\Tests\Support\Identity\RecordingMailer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * InviteUser against the real command bus, Doctrine and mail handler: the mail
 * leaves only after the command's transaction committed (ADR 0034).
 */
#[CoversClass(InviteUserHandler::class)]
#[CoversClass(SendAccountMailHandler::class)]
final class InviteUserThroughCommandBusTest extends KernelTestCase
{
    private const string INVITATION_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
    private const string OWNER_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';

    private Connection $connection;
    private InvitationRepository $invitations;
    private CommandBus $bus;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->invitations = $container->get(InvitationRepository::class);
        $this->bus = $container->get(CommandBus::class);
    }

    #[Test]
    public function theInvitationIsStoredWhenTheMailIsSent(): void
    {
        // The invitation exists when the mail goes out (that it is committed is shown by the transport failure test).
        $mailer = $this->mailer(new RecordingMailer(fn (): int => \count($this->connection->fetchFirstColumn(
            "SELECT id FROM identity_invitation WHERE email = 'ada@example.com' AND status = 'pending'",
        ))));

        $this->bus->dispatch(new InviteUser(self::INVITATION_ID, 'ada@example.com', ['GAME_MANAGER'], self::OWNER_ID));

        self::assertCount(1, $mailer->sent);
        self::assertSame([1], $mailer->probes);
        self::assertSame('ada@example.com', $mailer->sent[0]->getTo()[0]->getAddress());
        self::assertMatchesRegularExpression('~/accept-invitation#token=[A-Za-z0-9_-]{43}~', (string) $mailer->sent[0]->getTextBody());
    }

    #[Test]
    public function noMailLeavesWhenTheCommandIsRefused(): void
    {
        $mailer = $this->mailer(new RecordingMailer());
        $this->bus->dispatch(new CreateUser(self::OWNER_ID, 'ada@example.com', 'secret123', ['SOLO_PLAYER']));

        try {
            $this->bus->dispatch(new InviteUser(self::INVITATION_ID, 'ada@example.com', ['SOLO_PLAYER'], self::OWNER_ID));
            self::fail('An email with an account was invited.');
        } catch (EmailAlreadyInUse) {
            self::assertSame([], $mailer->sent);
            self::assertNull($this->invitations->pendingForEmail(Email::fromString('ada@example.com')));
        }
    }

    /**
     * Proves the mail is sent after commit: had it been sent inside the command's transaction,
     * its failure would have rolled the invitation back.
     */
    #[Test]
    public function aTransportFailureIsMailUnavailableAndTheInvitationStaysStored(): void
    {
        $this->mailer(new RecordingMailer(failure: new TransportException('smtp down')));

        try {
            $this->bus->dispatch(new InviteUser(self::INVITATION_ID, 'ada@example.com', ['SOLO_PLAYER'], self::OWNER_ID));
            self::fail('The mail failure was swallowed.');
        } catch (MailUnavailable $exception) {
            self::assertInstanceOf(TransportException::class, $exception->getPrevious());
        }

        $stored = $this->invitations->pendingForEmail(Email::fromString('ada@example.com'));
        self::assertNotNull($stored);
        self::assertSame(self::INVITATION_ID, $stored->id()->toString());
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
