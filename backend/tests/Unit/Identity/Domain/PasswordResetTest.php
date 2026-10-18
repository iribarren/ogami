<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\AccountToken;
use App\Identity\Domain\PasswordReset;
use App\Identity\Domain\PasswordResetId;
use App\Identity\Domain\PasswordResetNotUsable;
use App\Identity\Domain\UserId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordReset::class)]
#[CoversClass(PasswordResetId::class)]
#[CoversClass(PasswordResetNotUsable::class)]
final class PasswordResetTest extends TestCase
{
    private const string NOW = '2026-10-18T09:00:00+00:00';

    #[Test]
    public function itIsUnusedAndExpiresOneHourLater(): void
    {
        $reset = $this->request();

        self::assertSame('reset-1', $reset->id()->toString());
        self::assertSame('user-1', $reset->userId()->toString());
        self::assertSame(AccountToken::fromString('secret')->hash(), $reset->tokenHash());
        self::assertNull($reset->usedAt());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $reset->createdAt());
        self::assertEquals(new \DateTimeImmutable('2026-10-18T10:00:00+00:00'), $reset->expiresAt());
    }

    #[Test]
    public function itNeverKeepsTheTokenItself(): void
    {
        self::assertStringNotContainsString('secret', serialize($this->request()));
    }

    #[Test]
    public function itIsUsableUntilExactlyItsExpiryDate(): void
    {
        $reset = $this->request();

        self::assertTrue($reset->isUsableAt(new \DateTimeImmutable('2026-10-18T09:59:59+00:00')));
        self::assertFalse($reset->isUsableAt(new \DateTimeImmutable('2026-10-18T10:00:00+00:00')));
        // Same instant in another time zone.
        self::assertFalse($reset->isUsableAt(new \DateTimeImmutable('2026-10-18T12:00:00+02:00')));
    }

    #[Test]
    public function usingItRecordsWhenAndMakesItUnusable(): void
    {
        $reset = $this->request();
        $usedAt = new \DateTimeImmutable('2026-10-18T09:30:00+00:00');

        $reset->use($usedAt);

        self::assertEquals($usedAt, $reset->usedAt());
        self::assertFalse($reset->isUsableAt($usedAt));
    }

    #[Test]
    public function itCanBeUsedOnlyOnce(): void
    {
        $reset = $this->request();
        $reset->use(new \DateTimeImmutable('2026-10-18T09:30:00+00:00'));

        try {
            $reset->use(new \DateTimeImmutable('2026-10-18T09:31:00+00:00'));
            self::fail('A used reset was used again.');
        } catch (PasswordResetNotUsable) {
            self::assertEquals(new \DateTimeImmutable('2026-10-18T09:30:00+00:00'), $reset->usedAt());
        }
    }

    #[Test]
    public function anExpiredResetCannotBeUsedAndStaysUnused(): void
    {
        $reset = $this->request();

        try {
            $reset->use(new \DateTimeImmutable('2026-10-18T10:00:00+00:00'));
            self::fail('An expired reset was used.');
        } catch (PasswordResetNotUsable) {
            self::assertNull($reset->usedAt());
        }
    }

    #[Test]
    public function aBlankIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PasswordResetId::fromString(' ');
    }

    private function request(): PasswordReset
    {
        return PasswordReset::request(
            PasswordResetId::fromString('reset-1'),
            UserId::fromString('user-1'),
            AccountToken::fromString('secret'),
            new \DateTimeImmutable(self::NOW),
        );
    }
}
