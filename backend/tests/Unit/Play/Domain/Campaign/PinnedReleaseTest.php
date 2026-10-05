<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Play\Domain\Campaign\InvalidPinnedRelease;
use App\Play\Domain\Campaign\PinnedRelease;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PinnedRelease::class)]
#[CoversClass(InvalidPinnedRelease::class)]
final class PinnedReleaseTest extends TestCase
{
    #[Test]
    public function itReferencesOneReleaseByKeyAndVersion(): void
    {
        $pinned = PinnedRelease::of('mythic-style', 3, 'Mythic-style');

        self::assertSame('mythic-style', $pinned->gameSystemKey());
        self::assertSame(3, $pinned->releaseVersion());
        self::assertSame('Mythic-style', $pinned->gameSystemName());
    }

    #[Test]
    public function versionOneIsTheFirstValidVersion(): void
    {
        self::assertSame(1, PinnedRelease::of('free-journal', 1, 'Free journal')->releaseVersion());
    }

    #[Test]
    public function aVersionBelowOneIsRejected(): void
    {
        $this->expectException(InvalidPinnedRelease::class);
        $this->expectExceptionMessageIsOrContains('A pinned release version must be at least 1, got 0.');

        PinnedRelease::of('free-journal', 0, 'Free journal');
    }

    #[Test]
    public function aBlankKeyIsRejected(): void
    {
        $this->expectException(InvalidPinnedRelease::class);
        $this->expectExceptionMessageIsOrContains('A pinned release needs a GameSystem key.');

        PinnedRelease::of(' ', 1, 'Free journal');
    }

    #[Test]
    public function aBlankNameIsRejected(): void
    {
        $this->expectException(InvalidPinnedRelease::class);
        $this->expectExceptionMessageIsOrContains('A pinned release needs a GameSystem name.');

        PinnedRelease::of('free-journal', 1, '');
    }

    #[Test]
    public function equalityComparesKeyAndVersion(): void
    {
        $pinned = PinnedRelease::of('free-journal', 1, 'Free journal');

        self::assertTrue($pinned->equals(PinnedRelease::of('free-journal', 1, 'Free journal')));
        self::assertFalse($pinned->equals(PinnedRelease::of('free-journal', 2, 'Free journal')));
        self::assertFalse($pinned->equals(PinnedRelease::of('mythic-style', 1, 'Free journal')));
    }
}
