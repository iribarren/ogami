<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

use App\Play\Domain\Campaign\FlowRun\SheetChange;
use App\Play\Domain\GameSystem\Check\CheckPush;
use App\Play\Domain\GameSystem\Check\PushResult;
use App\Randomness\Domain\PoolDie;

/**
 * The push part of a pool check entry (ADR 0041), stored only once the roll was pushed: the label of
 * the cost, the whole pool after the push (dice that were re-rolled say so), its successes, the band
 * the roll had before the push and what the cost changed on the Character's sheet. Tracker changes
 * are not journalled, as the FlowRun does not journal them either.
 *
 * @phpstan-import-type SheetChangeData from SheetChangeContent
 *
 * @phpstan-type PushDieData array{group: string, face: int, success: bool, rerolled: bool}
 * @phpstan-type PushData array{label: string, dice: list<PushDieData>, successes: int, rolledBandKey: string, rolledBandLabel: string, changes: list<SheetChangeData>}
 */
final readonly class CheckPushContent
{
    /**
     * @param list<PushDieData>     $dice    in roll order, the same dice as the roll with the failed ones re-rolled
     * @param list<SheetChangeData> $changes the cost's sheet changes on the rolling Character, in apply order
     */
    private function __construct(
        public string $label,
        public array $dice,
        public int $successes,
        public string $rolledBandKey,
        public string $rolledBandLabel,
        public array $changes,
    ) {
    }

    /**
     * @param list<SheetChange> $changes
     *
     * @throws InvalidJournalEntryContent when the label is blank or too long, or a change is malformed
     */
    public static function fromResult(string $label, PushResult $result, string $rolledBandKey, string $rolledBandLabel, array $changes): self
    {
        return self::of(
            $label,
            array_map(static fn (PoolDie $die): array => ['group' => CheckPoolContent::GROUP, 'face' => $die->face(), 'success' => $die->isSuccess(), 'rerolled' => $die->isRerolled()], $result->roll->dice()),
            $result->roll->successes(),
            $rolledBandKey,
            $rolledBandLabel,
            array_map(static fn (SheetChange $change): array => $change->toArray(), $changes),
        );
    }

    /**
     * @param array<mixed> $data the stored push part
     *
     * @throws InvalidJournalEntryContent
     */
    public static function fromArray(array $data): self
    {
        return self::of(
            ContentData::string($data, 'label'),
            array_map(static fn (array $die): array => [
                'group' => ContentData::string($die, 'group'),
                'face' => ContentData::int($die, 'face'),
                'success' => ContentData::bool($die, 'success'),
                'rerolled' => ContentData::bool($die, 'rerolled'),
            ], ContentData::listOfArrays($data, 'dice')),
            ContentData::int($data, 'successes'),
            ContentData::string($data, 'rolledBandKey'),
            ContentData::string($data, 'rolledBandLabel'),
            ContentData::listOfArrays($data, 'changes'),
        );
    }

    /**
     * @param list<PushDieData>  $dice
     * @param list<array<mixed>> $changes
     *
     * @throws InvalidJournalEntryContent when the label is blank or too long, a band key is blank, the successes are not those
     *                                    of the dice, or a change is malformed
     */
    private static function of(string $label, array $dice, int $successes, string $rolledBandKey, string $rolledBandLabel, array $changes): self
    {
        if ('' === trim($label) || mb_strlen($label) > CheckPush::MAX_LABEL_LENGTH) {
            throw InvalidJournalEntryContent::malformed('label', \sprintf('1 to %d characters', CheckPush::MAX_LABEL_LENGTH));
        }

        if ('' === trim($rolledBandKey)) {
            throw InvalidJournalEntryContent::blank('A rolled band key');
        }

        if ($successes !== \count(array_filter($dice, static fn (array $die): bool => $die['success']))) {
            throw InvalidJournalEntryContent::malformed('successes', 'the number of dice that succeeded after the push');
        }

        return new self($label, $dice, $successes, $rolledBandKey, $rolledBandLabel, SheetChangeContent::checkedChanges($changes));
    }

    /**
     * @return PushData
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'dice' => $this->dice,
            'successes' => $this->successes,
            'rolledBandKey' => $this->rolledBandKey,
            'rolledBandLabel' => $this->rolledBandLabel,
            'changes' => $this->changes,
        ];
    }
}
