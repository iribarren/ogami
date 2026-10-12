<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\Identity;

use App\Play\Infrastructure\Identity\UuidFactIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UuidFactIdGenerator::class)]
final class UuidFactIdGeneratorTest extends TestCase
{
    #[Test]
    public function itHandsOutAValidTimeOrderedUuidEachTime(): void
    {
        $generator = new UuidFactIdGenerator();

        $first = $generator->generate()->toString();
        $second = $generator->generate()->toString();

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $second);
        self::assertNotSame($first, $second);
    }
}
