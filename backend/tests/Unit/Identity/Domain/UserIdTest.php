<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\InvalidUserId;
use App\Identity\Domain\UserId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserId::class)]
#[CoversClass(InvalidUserId::class)]
final class UserIdTest extends TestCase
{
    #[Test]
    public function itWrapsAnIdentifier(): void
    {
        $id = UserId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b');

        self::assertSame('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', $id->toString());
        self::assertTrue($id->equals(UserId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b')));
        self::assertFalse($id->equals(UserId::fromString('another-id')));
    }

    #[Test]
    public function itRejectsABlankIdentifier(): void
    {
        $this->expectException(InvalidUserId::class);

        UserId::fromString('  ');
    }
}
