<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

use App\Randomness\Domain\DiceGroup;
use App\Randomness\Domain\Roll;
use App\Randomness\Domain\RolledDie;

/**
 * A dice expression rolled on the server: its total and every die of each dice group, in the same
 * shape as the Randomness roll view.
 *
 * @phpstan-type RolledDieData array{value: int, kept: bool}
 * @phpstan-type DiceGroupData array{notation: string, sides: int, dice: list<RolledDieData>, subtotal: int}
 */
final readonly class RollContent implements JournalEntryContent
{
    public const string KIND = 'roll';

    /**
     * @param string              $expression the normalized notation, e.g. "2d6+1"
     * @param list<DiceGroupData> $groups     the dice groups in notation order
     */
    private function __construct(
        private string $expression,
        private int $total,
        private array $groups,
    ) {
    }

    public static function fromRoll(Roll $roll): self
    {
        return new self($roll->expression()->notation(), $roll->total(), self::groupsOf($roll));
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidJournalEntryContent
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ContentData::string($data, 'expression'),
            ContentData::int($data, 'total'),
            self::groupsFromArray($data),
        );
    }

    /**
     * The dice groups of a roll in the shape a roll stores them, for the contents that embed a roll.
     *
     * @return list<DiceGroupData>
     *
     * @internal used by the content value objects that embed a roll
     */
    public static function groupsOf(Roll $roll): array
    {
        return array_map(
            static fn (DiceGroup $group): array => [
                'notation' => $group->notation(),
                'sides' => $group->sides(),
                'dice' => array_map(
                    static fn (RolledDie $die): array => ['value' => $die->value(), 'kept' => $die->isKept()],
                    $group->dice(),
                ),
                'subtotal' => $group->subtotal(),
            ],
            $roll->groups(),
        );
    }

    /**
     * The dice groups of a stored content array, read strictly.
     *
     * @param array<mixed> $data the stored content, holding a "groups" list
     *
     * @return list<DiceGroupData>
     *
     * @throws InvalidJournalEntryContent
     *
     * @internal used by the content value objects that embed a roll
     */
    public static function groupsFromArray(array $data): array
    {
        return array_map(
            static fn (array $group): array => [
                'notation' => ContentData::string($group, 'notation'),
                'sides' => ContentData::int($group, 'sides'),
                'dice' => array_map(
                    static fn (array $die): array => ['value' => ContentData::int($die, 'value'), 'kept' => ContentData::bool($die, 'kept')],
                    ContentData::listOfArrays($group, 'dice'),
                ),
                'subtotal' => ContentData::int($group, 'subtotal'),
            ],
            ContentData::listOfArrays($data, 'groups'),
        );
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function expression(): string
    {
        return $this->expression;
    }

    public function total(): int
    {
        return $this->total;
    }

    /**
     * @return list<DiceGroupData> in notation order; each die in roll order, dropped ones included
     */
    public function groups(): array
    {
        return $this->groups;
    }

    /**
     * @return array{kind: string, expression: string, total: int, groups: list<DiceGroupData>}
     */
    public function toArray(): array
    {
        return ['kind' => self::KIND, 'expression' => $this->expression, 'total' => $this->total, 'groups' => $this->groups];
    }
}
