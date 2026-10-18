<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\AcceptInvitation;
use App\Identity\Application\AcceptInvitationHandler;
use App\Identity\Application\CreateUser;
use App\Identity\Application\EmailAlreadyInUse;
use App\Identity\Application\InvalidAccountLink;
use App\Identity\Application\InviteUser;
use App\Identity\Application\ListInvitations;
use App\Identity\Application\LookupInvitation;
use App\Identity\Application\ResendInvitation;
use App\Identity\Application\RevokeInvitation;
use App\Identity\Domain\Email;
use App\Identity\Domain\InvitationId;
use App\Identity\Domain\InvitationRepository;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserRepository;
use App\Identity\Infrastructure\Mail\SendAccountMailHandler;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Tests\Support\Identity\RecordingMailer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The invitation lifecycle against the real buses, Doctrine and mail handler.
 */
#[CoversClass(AcceptInvitationHandler::class)]
final class InvitationLifecycleThroughTheBusesTest extends KernelTestCase
{
    private const string INVITATION_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
    private const string OWNER_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';
    private const string USER_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d';

    private CommandBus $bus;
    private QueryBus $queries;
    private RecordingMailer $mailer;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->bus = $container->get(CommandBus::class);
        $this->queries = $container->get(QueryBus::class);
        $this->mailer = new RecordingMailer();
        $container->set(SendAccountMailHandler::class, new SendAccountMailHandler($this->mailer));
    }

    #[Test]
    public function anInvitedPersonLooksUpTheLinkAndAcceptsIt(): void
    {
        $this->bus->dispatch(new InviteUser(self::INVITATION_ID, 'ada@example.com', ['GAME_MANAGER', 'SOLO_PLAYER'], self::OWNER_ID));
        $token = $this->lastToken();

        $lookup = $this->queries->ask(new LookupInvitation($token));
        self::assertSame(['ada@example.com', ['GAME_MANAGER', 'SOLO_PLAYER']], [$lookup->email, $lookup->roles]);
        self::assertCount(1, $this->queries->ask(new ListInvitations()));

        $this->bus->dispatch(new AcceptInvitation($token, 'correct horse', self::USER_ID));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $user = self::getContainer()->get(UserRepository::class)->ofEmail(Email::fromString('ada@example.com'));
        self::assertNotNull($user);
        self::assertSame([Role::GameManager, Role::SoloPlayer], $user->roles());
        self::assertTrue(password_verify('correct horse', $user->passwordHash()));
        self::assertSame([], $this->queries->ask(new ListInvitations()));
        $this->expectException(InvalidAccountLink::class);

        $this->queries->ask(new LookupInvitation($token));
    }

    #[Test]
    public function anEmailThatGotAnAccountMeanwhileLeavesTheInvitationPending(): void
    {
        $this->bus->dispatch(new InviteUser(self::INVITATION_ID, 'ada@example.com', ['SOLO_PLAYER'], self::OWNER_ID));
        $token = $this->lastToken();
        $this->bus->dispatch(new CreateUser(self::OWNER_ID, 'ada@example.com', 'secret123', ['SOLO_PLAYER']));

        try {
            $this->bus->dispatch(new AcceptInvitation($token, 'correct horse', self::USER_ID));
            self::fail('The invitation was accepted for an email with an account.');
        } catch (EmailAlreadyInUse) {
            self::getContainer()->get(EntityManagerInterface::class)->clear();
            $invitations = $this->queries->ask(new ListInvitations());
            self::assertCount(1, $invitations);
            self::assertNull(self::getContainer()->get(UserRepository::class)->ofId(UserId::fromString(self::USER_ID)));
        }
    }

    #[Test]
    public function resendingReplacesTheLinkAndRevokingStopsIt(): void
    {
        $this->bus->dispatch(new InviteUser(self::INVITATION_ID, 'ada@example.com', ['SOLO_PLAYER'], self::OWNER_ID));
        $first = $this->lastToken();

        $this->bus->dispatch(new ResendInvitation(self::INVITATION_ID));
        $second = $this->lastToken();
        self::assertNotSame($first, $second);
        self::assertCount(2, $this->mailer->sent);

        $this->bus->dispatch(new RevokeInvitation(self::INVITATION_ID));
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        foreach ([$first, $second] as $token) {
            try {
                $this->queries->ask(new LookupInvitation($token));
                self::fail('A link of a revoked or replaced invitation still works.');
            } catch (InvalidAccountLink) {
                // Expected: the old link was replaced, the new one revoked.
            }
        }

        self::assertNotNull(self::getContainer()->get(InvitationRepository::class)->ofId(InvitationId::fromString(self::INVITATION_ID)));
    }

    private function lastToken(): string
    {
        $last = end($this->mailer->sent);
        $text = false === $last ? '' : (string) $last->getTextBody();
        preg_match('~#token=([A-Za-z0-9_-]{43})~', $text, $matches);

        return $matches[1] ?? self::fail('No link in the email.');
    }
}
