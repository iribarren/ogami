<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Infrastructure\RandomTokenGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RandomTokenGenerator::class)]
final class RandomTokenGeneratorTest extends TestCase
{
    #[Test]
    public function itGenerates32RandomBytesAsUnpaddedBase64Url(): void
    {
        $value = new RandomTokenGenerator()->generate()->value();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $value);
        self::assertSame(32, \strlen(base64_decode(strtr($value, '-_', '+/'), true) ?: ''));
    }

    #[Test]
    public function everyTokenIsDifferentAndHashesToSha256Hex(): void
    {
        $generator = new RandomTokenGenerator();
        $first = $generator->generate();
        $second = $generator->generate();

        self::assertNotSame($first->value(), $second->value());
        self::assertSame(hash('sha256', $first->value()), $first->hash());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first->hash());
    }
}
