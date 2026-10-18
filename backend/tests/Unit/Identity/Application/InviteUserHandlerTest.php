<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\AccountLinks;
use App\Identity\Application\InvitationIdAlreadyInUse;
use App\Identity\Application\InviteUser;
use App\Identity\Application\InviteUserHandler;
use App\Tests\Support\Identity\FixedClock;
use App\Tests\Support\Identity\InMemoryInvitationRepository;
use App\Tests\Support\Identity\InMemoryUserRepository;
use App\Tests\Support\Identity\SequentialTokenGenerator;
use App\Tests\Support\Identity\SpyAccountMailer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InviteUserHandler::class)]
#[CoversClass(InvitationIdAlreadyInUse::class)]
final class InviteUserHandlerTest extends TestCase
{
    #[Test]
    public function anInvitationIdThatIsAlreadyInUseIsRefusedBeforeAnythingIsSavedOrSent(): void
    {
        $invitations = new InMemoryInvitationRepository();
        $mailer = new SpyAccountMailer();
        $handler = new InviteUserHandler(
            $invitations,
            new InMemoryUserRepository(),
            new SequentialTokenGenerator(),
            new AccountLinks('http://localhost:8080'),
            $mailer,
            new FixedClock(),
        );
        $handler(new InviteUser('invitation-1', 'ada@example.com', ['SOLO_PLAYER'], 'owner-1'));

        try {
            $handler(new InviteUser('invitation-1', 'bob@example.com', ['SOLO_PLAYER'], 'owner-1'));
            self::fail('The id of an existing invitation was reused.');
        } catch (InvitationIdAlreadyInUse $exception) {
            self::assertStringContainsString('invitation-1', $exception->getMessage());
            self::assertCount(1, $invitations->all());
            self::assertCount(1, $mailer->invitations);
        }
    }
}
