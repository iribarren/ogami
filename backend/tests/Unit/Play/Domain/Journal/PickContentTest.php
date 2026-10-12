<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\JournalEntryContents;
use App\Play\Domain\Journal\PickContent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PickContent::class)]
#[CoversClass(InvalidJournalEntryContent::class)]
final class PickContentTest extends TestCase
{
    #[Test]
    public function aPickKeepsTheKindTheTagsAndTheItemsWithTheirNamesAtPickTime(): void
    {
        $pick = PickContent::of(ItemKind::Npc, ['rival'], [['id' => 'a', 'name' => 'Ana'], ['id' => 'b', 'name' => 'Bea']]);

        self::assertSame('pick', $pick->kind());
        self::assertSame(
            ['kind' => 'pick', 'of' => 'npc', 'tags' => ['rival'], 'picked' => [['id' => 'a', 'name' => 'Ana'], ['id' => 'b', 'name' => 'Bea']]],
            $pick->toArray(),
        );
        self::assertEquals($pick, JournalEntryContents::fromArray($pick->toArray()));
    }

    #[Test]
    public function aPickOfNothingIsEmpty(): void
    {
        $none = PickContent::of(ItemKind::Thread, [], []);

        self::assertSame(['kind' => 'pick', 'of' => 'thread', 'tags' => [], 'picked' => []], $none->toArray());
        self::assertEquals($none, JournalEntryContents::fromArray($none->toArray()));
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function malformed(): iterable
    {
        $valid = ['kind' => 'pick', 'of' => 'npc', 'tags' => ['rival'], 'picked' => [['id' => 'a', 'name' => 'Ana']]];

        yield 'without of' => [array_diff_key($valid, ['of' => true])];
        yield 'unknown of' => [['of' => 'faction'] + $valid];
        yield 'tags not a list' => [['tags' => 'rival'] + $valid];
        yield 'a tag not a string' => [['tags' => [1]] + $valid];
        yield 'a blank tag' => [['tags' => [' ']] + $valid];
        yield 'without picked' => [array_diff_key($valid, ['picked' => true])];
        yield 'picked not a list' => [['picked' => 'Ana'] + $valid];
        yield 'an item not an object' => [['picked' => ['Ana']] + $valid];
        yield 'an item without a name' => [['picked' => [['id' => 'a']]] + $valid];
        yield 'a blank id' => [['picked' => [['id' => '', 'name' => 'Ana']]] + $valid];
        yield 'a blank name' => [['picked' => [['id' => 'a', 'name' => ' ']]] + $valid];
        yield 'a repeated id' => [['picked' => [['id' => 'a', 'name' => 'Ana'], ['id' => 'a', 'name' => 'Ana']]] + $valid];
        yield 'more than ten items' => [['picked' => array_map(static fn (int $n): array => ['id' => (string) $n, 'name' => 'N'.$n], range(1, 11))] + $valid];
    }

    /**
     * @param array<mixed> $data
     */
    #[Test]
    #[DataProvider('malformed')]
    public function malformedDataIsRejected(array $data): void
    {
        $this->expectException(InvalidJournalEntryContent::class);

        JournalEntryContents::fromArray($data);
    }
}
