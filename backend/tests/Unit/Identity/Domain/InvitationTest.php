<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\AccountToken;
use App\Identity\Domain\Email;
use App\Identity\Domain\Invitation;
use App\Identity\Domain\InvitationExpired;
use App\Identity\Domain\InvitationId;
use App\Identity\Domain\InvitationMustHaveARole;
use App\Identity\Domain\InvitationNotPending;
use App\Identity\Domain\InvitationStatus;
use App\Identity\Domain\Role;
use App\Identity\Domain\UnknownStoredRole;
use App\Identity\Domain\UserId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Invitation::class)]
#[CoversClass(InvitationId::class)]
#[CoversClass(InvitationStatus::class)]
#[CoversClass(InvitationMustHaveARole::class)]
#[CoversClass(InvitationNotPending::class)]
#[CoversClass(InvitationExpired::class)]
#[CoversClass(UnknownStoredRole::class)]
#[CoversClass(Role::class)]
final class InvitationTest extends TestCase
{
    private const string NOW = '2026-10-18T09:00:00+00:00';

    #[Test]
    public function itStartsPendingAndExpiresSevenDaysLater(): void
    {
        $invitation = $this->invite([Role::GameManager, Role::SoloPlayer, Role::GameManager]);

        self::assertSame('invitation-1', $invitation->id()->toString());
        self::assertSame('ada@example.com', $invitation->email()->toString());
        self::assertSame([Role::GameManager, Role::SoloPlayer], $invitation->roles());
        self::assertSame(AccountToken::fromString('secret')->hash(), $invitation->tokenHash());
        self::assertSame('owner-1', $invitation->invitedBy()->toString());
        self::assertSame(InvitationStatus::Pending, $invitation->status());
        self::assertTrue($invitation->isPending());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $invitation->createdAt());
        self::assertEquals(new \DateTimeImmutable('2026-10-25T09:00:00+00:00'), $invitation->expiresAt());
    }

    #[Test]
    public function itNeverKeepsTheTokenItself(): void
    {
        self::assertStringNotContainsString('secret', serialize($this->invite([Role::SoloPlayer])));
    }

    #[Test]
    public function itRefusesToBeCreatedWithoutARole(): void
    {
        $this->expectException(InvitationMustHaveARole::class);

        $this->invite([]);
    }

    #[Test]
    public function itExpiresExactlyAtItsExpiryDate(): void
    {
        $invitation = $this->invite([Role::SoloPlayer]);

        self::assertFalse($invitation->isExpiredAt(new \DateTimeImmutable('2026-10-25T08:59:59+00:00')));
        self::assertTrue($invitation->isExpiredAt(new \DateTimeImmutable('2026-10-25T09:00:00+00:00')));
        // Same instant in another time zone.
        self::assertTrue($invitation->isExpiredAt(new \DateTimeImmutable('2026-10-25T11:00:00+02:00')));
    }

    #[Test]
    public function aPendingInvitationCanBeRevokedOnce(): void
    {
        $invitation = $this->invite([Role::SoloPlayer]);

        $invitation->revoke();

        self::assertSame(InvitationStatus::Revoked, $invitation->status());
        self::assertFalse($invitation->isPending());

        $this->expectException(InvitationNotPending::class);

        $invitation->revoke();
    }

    #[Test]
    public function renewingGivesANewTokenAndAFullValidityFromNow(): void
    {
        $invitation = $this->invite([Role::SoloPlayer]);

        // After the first expiry: an expired pending invitation can be resent.
        $invitation->renew(AccountToken::fromString('fresh'), new \DateTimeImmutable('2026-10-26T12:00:00+00:00'));

        self::assertSame(AccountToken::fromString('fresh')->hash(), $invitation->tokenHash());
        self::assertEquals(new \DateTimeImmutable('2026-11-02T12:00:00+00:00'), $invitation->expiresAt());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $invitation->createdAt());
        self::assertTrue($invitation->isPending());
        self::assertFalse($invitation->isExpiredAt(new \DateTimeImmutable('2026-10-27T00:00:00+00:00')));
    }

    #[Test]
    public function aRevokedOrAcceptedInvitationCannotBeRenewed(): void
    {
        $revoked = $this->invite([Role::SoloPlayer]);
        $revoked->revoke();
        $accepted = $this->invite([Role::SoloPlayer]);
        $accepted->accept(new \DateTimeImmutable(self::NOW));

        foreach ([$revoked, $accepted] as $invitation) {
            $hash = $invitation->tokenHash();
            try {
                $invitation->renew(AccountToken::fromString('fresh'), new \DateTimeImmutable(self::NOW));
                self::fail('A used invitation was renewed.');
            } catch (InvitationNotPending) {
                self::assertSame($hash, $invitation->tokenHash());
            }
        }
    }

    #[Test]
    public function aPendingInvitationCanBeAcceptedBeforeItExpires(): void
    {
        $invitation = $this->invite([Role::SoloPlayer]);

        $invitation->accept(new \DateTimeImmutable('2026-10-25T08:59:59+00:00'));

        self::assertSame(InvitationStatus::Accepted, $invitation->status());
        self::assertFalse($invitation->isPending());
    }

    #[Test]
    public function anExpiredInvitationCannotBeAccepted(): void
    {
        $invitation = $this->invite([Role::SoloPlayer]);

        try {
            $invitation->accept(new \DateTimeImmutable('2026-10-25T09:00:00+00:00'));
            self::fail('An expired invitation was accepted.');
        } catch (InvitationExpired) {
            self::assertSame(InvitationStatus::Pending, $invitation->status());
        }
    }

    #[Test]
    public function aRevokedOrAcceptedInvitationCannotBeAcceptedAgain(): void
    {
        $revoked = $this->invite([Role::SoloPlayer]);
        $revoked->revoke();
        $accepted = $this->invite([Role::SoloPlayer]);
        $accepted->accept(new \DateTimeImmutable(self::NOW));

        foreach ([$revoked, $accepted] as $invitation) {
            try {
                $invitation->accept(new \DateTimeImmutable(self::NOW));
                self::fail('A used invitation was accepted.');
            } catch (InvitationNotPending) {
                self::assertFalse($invitation->isPending());
            }
        }
    }

    #[Test]
    public function aStoredRoleThatIsNoLongerKnownIsADomainErrorNotAValueError(): void
    {
        // Unserialising is the way to hold a stored role the domain no longer knows: the property is readonly.
        $invitation = unserialize(str_replace(
            's:11:"SOLO_PLAYER"',
            's:14:"DUNGEON_MASTER"',
            serialize($this->invite([Role::SoloPlayer])),
        ));
        self::assertInstanceOf(Invitation::class, $invitation);

        $this->expectException(UnknownStoredRole::class);
        $this->expectExceptionMessageIsOrContains('DUNGEON_MASTER');

        $invitation->roles();
    }

    /**
     * @param list<Role> $roles
     */
    private function invite(array $roles): Invitation
    {
        return Invitation::invite(
            InvitationId::fromString('invitation-1'),
            Email::fromString('Ada@Example.com'),
            $roles,
            AccountToken::fromString('secret'),
            UserId::fromString('owner-1'),
            new \DateTimeImmutable(self::NOW),
        );
    }
}
