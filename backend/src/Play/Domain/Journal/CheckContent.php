<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckResult;

/**
 * A Check of the pinned release performed for a Character (ADR 0024): the Character and the check
 * as they were named then, the dice rolled, the modifier read from the sheet, the situational
 * modifier, the Check difficulty that applied, the total and the Outcome band it fell in.
 *
 * @phpstan-import-type DiceGroupData from RollContent
 */
final readonly class CheckContent implements JournalEntryContent
{
    public const string KIND = 'check';

    /**
     * @param string              $expression the normalized notation of the check's dice, e.g. "1d10"
     * @param list<DiceGroupData> $groups     the dice groups in notation order, as a roll stores them
     * @param int                 $diceTotal  the value of the dice expression, counting kept dice only
     * @param ?int                $difficulty null when the check declares no difficulty
     */
    private function __construct(
        private string $characterId,
        private string $characterName,
        private string $checkKey,
        private string $checkLabel,
        private string $expression,
        private array $groups,
        private int $diceTotal,
        private int $modifier,
        private int $situational,
        private ?int $difficulty,
        private int $total,
        private string $bandKey,
        private string $bandLabel,
    ) {
    }

    /**
     * @throws InvalidJournalEntryContent when the Character id is blank
     */
    public static function fromResult(string $characterId, string $characterName, Check $check, CheckResult $result): self
    {
        return self::of(
            $characterId,
            $characterName,
            $check->key,
            $check->label,
            $result->roll->expression()->notation(),
            RollContent::groupsOf($result->roll),
            $result->roll->total(),
            $result->modifier,
            $result->situational,
            $result->difficulty,
            $result->total,
            $result->band->key,
            $result->band->label,
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
            ContentData::string($data, 'checkKey'),
            ContentData::string($data, 'checkLabel'),
            ContentData::string($data, 'expression'),
            RollContent::groupsFromArray($data),
            ContentData::int($data, 'diceTotal'),
            ContentData::int($data, 'modifier'),
            ContentData::int($data, 'situational'),
            ContentData::nullableInt($data, 'difficulty'),
            ContentData::int($data, 'total'),
            ContentData::string($data, 'bandKey'),
            ContentData::string($data, 'bandLabel'),
        );
    }

    /**
     * @param list<DiceGroupData> $groups
     *
     * @throws InvalidJournalEntryContent when an id or key is blank, or the total is not the dice plus the modifiers
     */
    private static function of(
        string $characterId,
        string $characterName,
        string $checkKey,
        string $checkLabel,
        string $expression,
        array $groups,
        int $diceTotal,
        int $modifier,
        int $situational,
        ?int $difficulty,
        int $total,
        string $bandKey,
        string $bandLabel,
    ): self {
        foreach (['A Character id' => $characterId, 'A check key' => $checkKey, 'A band key' => $bandKey] as $what => $value) {
            if ('' === trim($value)) {
                throw InvalidJournalEntryContent::blank($what);
            }
        }

        // A sum that overflows the integers is a float, and never equals the total.
        if ($diceTotal + $modifier + $situational !== $total) {
            throw InvalidJournalEntryContent::malformed('total', 'the dice total plus the modifier and the situational modifier');
        }

        return new self($characterId, $characterName, $checkKey, $checkLabel, $expression, $groups, $diceTotal, $modifier, $situational, $difficulty, $total, $bandKey, $bandLabel);
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

    public function checkKey(): string
    {
        return $this->checkKey;
    }

    public function checkLabel(): string
    {
        return $this->checkLabel;
    }

    public function expression(): string
    {
        return $this->expression;
    }

    /**
     * @return list<DiceGroupData> in notation order; each die in roll order, dropped ones included
     */
    public function groups(): array
    {
        return $this->groups;
    }

    public function diceTotal(): int
    {
        return $this->diceTotal;
    }

    public function modifier(): int
    {
        return $this->modifier;
    }

    public function situational(): int
    {
        return $this->situational;
    }

    public function difficulty(): ?int
    {
        return $this->difficulty;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function bandKey(): string
    {
        return $this->bandKey;
    }

    public function bandLabel(): string
    {
        return $this->bandLabel;
    }

    /**
     * @return array{kind: string, characterId: string, characterName: string, checkKey: string, checkLabel: string, expression: string, groups: list<DiceGroupData>, diceTotal: int, modifier: int, situational: int, difficulty: ?int, total: int, bandKey: string, bandLabel: string}
     */
    public function toArray(): array
    {
        return [
            'kind' => self::KIND,
            'characterId' => $this->characterId,
            'characterName' => $this->characterName,
            'checkKey' => $this->checkKey,
            'checkLabel' => $this->checkLabel,
            'expression' => $this->expression,
            'groups' => $this->groups,
            'diceTotal' => $this->diceTotal,
            'modifier' => $this->modifier,
            'situational' => $this->situational,
            'difficulty' => $this->difficulty,
            'total' => $this->total,
            'bandKey' => $this->bandKey,
            'bandLabel' => $this->bandLabel,
        ];
    }
}
