<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

use App\Play\Domain\Campaign\FlowRun\SheetChange;
use App\Play\Domain\GameSystem\Check\BurnResult;
use App\Play\Domain\GameSystem\Check\CheckPush;

/**
 * The burn part of a challenge check entry (ADR 0049), stored only once the momentum was burned: the
 * label of the cost, the momentum value that was read, the score it became (capped), the band the
 * roll had before the burn and what the cost changed on the Character's sheet. Tracker changes are
 * not journalled, as the FlowRun does not journal them either. Entries stored before burning have none.
 *
 * @phpstan-import-type SheetChangeData from SheetChangeContent
 *
 * @phpstan-type BurnData array{label: string, momentum: int, score: int, rolledBandKey: string, rolledBandLabel: string, changes: list<SheetChangeData>}
 */
final readonly class CheckChallengeBurnContent
{
    /**
     * @param list<SheetChangeData> $changes the cost's sheet changes on the rolling Character, in apply order
     */
    private function __construct(
        public string $label,
        public int $momentum,
        public int $score,
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
    public static function fromResult(string $label, BurnResult $result, string $rolledBandKey, string $rolledBandLabel, array $changes): self
    {
        return self::of($label, $result->momentum, $result->score, $rolledBandKey, $rolledBandLabel, array_map(static fn (SheetChange $change): array => $change->toArray(), $changes));
    }

    /**
     * @param array<mixed> $data the stored burn part
     *
     * @throws InvalidJournalEntryContent
     */
    public static function fromArray(array $data): self
    {
        return self::of(
            ContentData::string($data, 'label'),
            ContentData::int($data, 'momentum'),
            ContentData::int($data, 'score'),
            ContentData::string($data, 'rolledBandKey'),
            ContentData::string($data, 'rolledBandLabel'),
            ContentData::listOfArrays($data, 'changes'),
        );
    }

    /**
     * @param list<array<mixed>> $changes
     *
     * @throws InvalidJournalEntryContent when the label is blank or too long, a rolled band key or label is blank, or a change is malformed
     */
    private static function of(string $label, int $momentum, int $score, string $rolledBandKey, string $rolledBandLabel, array $changes): self
    {
        if ('' === trim($label) || mb_strlen($label) > CheckPush::MAX_LABEL_LENGTH) {
            throw InvalidJournalEntryContent::malformed('label', \sprintf('1 to %d characters', CheckPush::MAX_LABEL_LENGTH));
        }

        if ('' === trim($rolledBandKey)) {
            throw InvalidJournalEntryContent::blank('A rolled band key');
        }

        // A published check band always has a label (the translator refuses a blank one).
        if ('' === trim($rolledBandLabel)) {
            throw InvalidJournalEntryContent::blank('A rolled band label');
        }

        return new self($label, $momentum, $score, $rolledBandKey, $rolledBandLabel, SheetChangeContent::checkedChanges($changes));
    }

    /**
     * @return BurnData
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'momentum' => $this->momentum,
            'score' => $this->score,
            'rolledBandKey' => $this->rolledBandKey,
            'rolledBandLabel' => $this->rolledBandLabel,
            'changes' => $this->changes,
        ];
    }
}
