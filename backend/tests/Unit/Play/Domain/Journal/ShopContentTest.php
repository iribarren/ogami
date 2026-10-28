<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\JournalEntryContents;
use App\Play\Domain\Journal\ShopContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShopContent::class)]
#[CoversClass(JournalEntryContents::class)]
final class ShopContentTest extends TestCase
{
    /**
     * Vi sells a rope at 2 and buys three loaves of bread at 3 each: 50 eb become 43.
     */
    public static function market(): ShopContent
    {
        return ShopContent::of('char-vi', 'Vi', [['gear' => 'bread', 'name' => 'Bread', 'quantity' => 3, 'price' => 3]], [['field' => 'pack', 'gear' => 'rope', 'name' => 'Rope', 'quantity' => 1, 'price' => 2]], 50, 43);
    }

    #[Test]
    public function aShopTransactionKeepsItsLinesAndTheCurrencyBeforeAndAfter(): void
    {
        $content = self::market();

        self::assertSame('shop', $content->kind());
        self::assertSame([
            'kind' => 'shop',
            'characterId' => 'char-vi',
            'characterName' => 'Vi',
            'bought' => [['gear' => 'bread', 'name' => 'Bread', 'quantity' => 3, 'price' => 3]],
            'sold' => [['field' => 'pack', 'gear' => 'rope', 'name' => 'Rope', 'quantity' => 1, 'price' => 2]],
            'before' => 50,
            'after' => 43,
        ], $content->toArray());
        self::assertEquals($content, JournalEntryContents::fromArray($content->toArray()));
    }

    #[Test]
    public function aPlayerWhoLeftWithoutTradingIsRecordedToo(): void
    {
        $content = ShopContent::of('char-vi', 'Vi', [], [], 50, 50);

        self::assertEquals($content, JournalEntryContents::fromArray($content->toArray()));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}> fields replacing the market's, and the refusal
     */
    public static function malformed(): iterable
    {
        yield 'a blank Character' => [['characterId' => ' '], 'A Character id must not be blank.'];
        yield 'no currency after' => [['after' => null], '"after" must be an integer.'];
        yield 'bought lines that are no list' => [['bought' => 'bread'], '"bought" must be a list'];
        yield 'a bought line without a name' => [['bought' => [['gear' => 'bread', 'quantity' => 3, 'price' => 3]]], '"name" must be a string.'];
        yield 'a bought line of no unit' => [['bought' => [['gear' => 'bread', 'name' => 'Bread', 'quantity' => 0, 'price' => 3]]], '"quantity" must be a positive integer.'];
        yield 'a sold line with a negative price' => [['sold' => [['field' => 'pack', 'gear' => 'rope', 'name' => 'Rope', 'quantity' => 1, 'price' => -2]]], '"price" must be an integer of at least 0.'];
        yield 'a sold line without its field' => [['sold' => [['gear' => 'rope', 'name' => 'Rope', 'quantity' => 1, 'price' => 2]]], '"field" must be a string.'];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('malformed')]
    public function aMalformedStoredTransactionIsRefused(array $fields, string $message): void
    {
        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        ShopContent::fromArray($fields + self::market()->toArray());
    }
}
