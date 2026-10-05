<?php

declare(strict_types=1);

namespace App\Tests\Unit\Randomness\Infrastructure;

use App\Randomness\Infrastructure\SecureRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecureRandomNumberGenerator::class)]
final class SecureRandomNumberGeneratorTest extends TestCase
{
    #[Test]
    public function itStaysWithinTheInclusiveBounds(): void
    {
        $generator = new SecureRandomNumberGenerator();
        $seen = [];

        for ($i = 0; $i < 600; ++$i) {
            $number = $generator->between(1, 6);
            self::assertGreaterThanOrEqual(1, $number);
            self::assertLessThanOrEqual(6, $number);
            $seen[$number] = true;
        }

        // 600 rolls of a d6 missing a face has a probability below 1e-40.
        self::assertCount(6, $seen);
    }
}
