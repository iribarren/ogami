<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\Email;
use App\Identity\Domain\InvalidEmail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Email::class)]
#[CoversClass(InvalidEmail::class)]
final class EmailTest extends TestCase
{
    #[Test]
    public function itNormalizesTheAddress(): void
    {
        self::assertSame('ada@example.com', Email::fromString('  Ada@Example.COM ')->toString());
    }

    #[Test]
    public function addressesThatNormalizeAlikeAreEqual(): void
    {
        self::assertTrue(Email::fromString('ADA@example.com')->equals(Email::fromString('ada@example.com')));
        self::assertFalse(Email::fromString('ada@example.com')->equals(Email::fromString('bob@example.com')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAddresses(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'no at sign' => ['ada.example.com'];
        yield 'no domain' => ['ada@'];
        yield 'no local part' => ['@example.com'];
    }

    #[Test]
    #[DataProvider('invalidAddresses')]
    public function itRejectsInvalidAddresses(string $address): void
    {
        $this->expectException(InvalidEmail::class);

        Email::fromString($address);
    }
}
