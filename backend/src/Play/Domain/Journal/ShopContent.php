<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

/**
 * What a Character traded at a shop step (ADR 0045): the Character as it was named then, the gear
 * bought and sold in request order, each with its quantity and unit price, and the currency before
 * and after. Leaving with an empty transaction is recorded too, with no lines.
 *
 * @phpstan-type BoughtData array{gear: string, name: string, quantity: int, price: int}
 * @phpstan-type SoldData array{field: string, gear: string, name: string, quantity: int, price: int}
 */
final readonly class ShopContent implements JournalEntryContent
{
    public const string KIND = 'shop';

    /**
     * @param list<BoughtData> $bought
     * @param list<SoldData>   $sold
     */
    private function __construct(
        private string $characterId,
        private string $characterName,
        private array $bought,
        private array $sold,
        private int $before,
        private int $after,
    ) {
    }

    /**
     * @param list<array<mixed>> $bought each {gear, name, quantity, price}, in request order
     * @param list<array<mixed>> $sold   each {field, gear, name, quantity, price}, in request order
     *
     * @throws InvalidJournalEntryContent when the Character id is blank or a line is malformed
     */
    public static function of(string $characterId, string $characterName, array $bought, array $sold, int $before, int $after): self
    {
        if ('' === trim($characterId)) {
            throw InvalidJournalEntryContent::blank('A Character id');
        }

        return new self(
            $characterId,
            $characterName,
            array_map(static fn (array $line): array => ['gear' => ContentData::string($line, 'gear'), ...self::unit($line)], $bought),
            array_map(static fn (array $line): array => ['field' => ContentData::string($line, 'field'), 'gear' => ContentData::string($line, 'gear'), ...self::unit($line)], $sold),
            $before,
            $after,
        );
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidJournalEntryContent
     */
    public static function fromArray(array $data): self
    {
        return self::of(
            ContentData::string($data, 'characterId'),
            ContentData::string($data, 'characterName'),
            ContentData::listOfArrays($data, 'bought'),
            ContentData::listOfArrays($data, 'sold'),
            ContentData::int($data, 'before'),
            ContentData::int($data, 'after'),
        );
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function characterId(): string
    {
        return $this->characterId;
    }

    public function characterName(): string
    {
        return $this->characterName;
    }

    /**
     * @return list<BoughtData> in request order
     */
    public function bought(): array
    {
        return $this->bought;
    }

    /**
     * @return list<SoldData> in request order
     */
    public function sold(): array
    {
        return $this->sold;
    }

    public function before(): int
    {
        return $this->before;
    }

    public function after(): int
    {
        return $this->after;
    }

    /**
     * @return array{kind: string, characterId: string, characterName: string, bought: list<BoughtData>, sold: list<SoldData>, before: int, after: int}
     */
    public function toArray(): array
    {
        return [
            'kind' => self::KIND,
            'characterId' => $this->characterId,
            'characterName' => $this->characterName,
            'bought' => $this->bought,
            'sold' => $this->sold,
            'before' => $this->before,
            'after' => $this->after,
        ];
    }

    /**
     * The name, quantity and unit price a line shares with the others.
     *
     * @param array<mixed> $line
     *
     * @return array{name: string, quantity: int, price: int}
     *
     * @throws InvalidJournalEntryContent
     */
    private static function unit(array $line): array
    {
        $quantity = ContentData::int($line, 'quantity');
        if ($quantity < 1) {
            throw InvalidJournalEntryContent::malformed('quantity', 'a positive integer');
        }

        $price = ContentData::int($line, 'price');
        if ($price < 0) {
            throw InvalidJournalEntryContent::malformed('price', 'an integer of at least 0');
        }

        return ['name' => ContentData::string($line, 'name'), 'quantity' => $quantity, 'price' => $price];
    }
}
