<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\AcceptInvitation;
use App\Identity\Application\AcceptInvitationHandler;
use App\Identity\Application\Clock;
use App\Identity\Application\InvalidAccountLink;
use App\Identity\Application\InvitationLoader;
use App\Identity\Domain\AccountToken;
use App\Identity\Domain\Email;
use App\Identity\Domain\Invitation;
use App\Identity\Domain\InvitationId;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserId;
use App\Tests\Support\Identity\FakePasswordHasher;
use App\Tests\Support\Identity\InMemoryInvitationRepository;
use App\Tests\Support\Identity\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AcceptInvitationHandler::class)]
#[CoversClass(InvitationLoader::class)]
final class AcceptInvitationHandlerTest extends TestCase
{
    private const string INVITED_AT = '2026-10-18T09:00:00+00:00';

    #[Test]
    public function itReadsTheClockOnceSoTheInvitationCannotExpireMidCommand(): void
    {
        // The first reading is one second before the expiry, any later one after it: a second read would
        // let the loader accept the link and the domain refuse it with InvitationExpired.
        $clock = new class implements Clock {
            public int $reads = 0;

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable(1 === ++$this->reads ? '2026-10-25T08:59:59+00:00' : '2026-10-25T09:00:01+00:00');
            }
        };
        [$handler, $users] = $this->handler($clock);

        $handler(new AcceptInvitation('secret', 'correct horse', 'user-1'));

        self::assertSame(1, $clock->reads);
        self::assertSame(1, $users->count());
    }

    #[Test]
    public function anInvitationExpiringExactlyNowIsAnInvalidLink(): void
    {
        $clock = new class implements Clock {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-10-25T09:00:00+00:00');
            }
        };
        [$handler, $users] = $this->handler($clock);

        try {
            $handler(new AcceptInvitation('secret', 'correct horse', 'user-1'));
            self::fail('An invitation expiring now was accepted.');
        } catch (InvalidAccountLink) {
            self::assertSame(0, $users->count());
        }
    }

    /**
     * @return array{AcceptInvitationHandler, InMemoryUserRepository}
     */
    private function handler(Clock $clock): array
    {
        $invitations = new InMemoryInvitationRepository();
        $invitations->save(Invitation::invite(
            InvitationId::fromString('invitation-1'),
            Email::fromString('ada@example.com'),
            [Role::SoloPlayer],
            AccountToken::fromString('secret'),
            UserId::fromString('owner-1'),
            new \DateTimeImmutable(self::INVITED_AT),
        ));
        $users = new InMemoryUserRepository();

        return [
            new AcceptInvitationHandler($invitations, new InvitationLoader($invitations), $users, new FakePasswordHasher(), $clock),
            $users,
        ];
    }
}
