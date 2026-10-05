<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseId;
use App\Studio\Domain\Release\ReleaseId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseId::class)]
#[CoversClass(InvalidReleaseId::class)]
final class ReleaseIdTest extends TestCase
{
    #[Test]
    public function itWrapsAnIdentifier(): void
    {
        $id = ReleaseId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b');

        self::assertSame('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', $id->toString());
        self::assertTrue($id->equals(ReleaseId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b')));
        self::assertFalse($id->equals(ReleaseId::fromString('another-id')));
    }

    #[Test]
    public function itRejectsABlankIdentifier(): void
    {
        $this->expectException(InvalidReleaseId::class);

        ReleaseId::fromString('  ');
    }
}
