<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

/**
 * What a Flow step's sheet Effects changed on a Character (ADR 0027), recorded right after the
 * step's own entry: the Character as it was named then and each change in apply order, the field,
 * its value before and after (a track's current value) and the dice rolled for it, if any.
 *
 * @phpstan-type SheetChangeData array{field: string, before: int, after: int, dice: ?array{expression: string, total: int}}
 */
final readonly class SheetChangeContent implements JournalEntryContent
{
    public const string KIND = 'sheet-change';

    /**
     * @param non-empty-list<SheetChangeData> $changes
     */
    private function __construct(
        private string $characterId,
        private string $characterName,
        private array $changes,
    ) {
    }

    /**
     * @param list<array<mixed>> $changes each {field, before, after, dice: ?{expression, total}}, in apply order
     *
     * @throws InvalidJournalEntryContent when the Character id or a field is blank, a change is malformed or there is none
     */
    public static function of(string $characterId, string $characterName, array $changes): self
    {
        if ('' === trim($characterId)) {
            throw InvalidJournalEntryContent::blank('A Character id');
        }

        $checked = self::checkedChanges($changes);

        if ([] === $checked) {
            throw InvalidJournalEntryContent::malformed('changes', 'a list of at least one change');
        }

        return new self($characterId, $characterName, $checked);
    }

    /**
     * The changes of a sheet Effects list as the journal keeps them, each checked.
     *
     * @param list<array<mixed>> $changes each {field, before, after, dice: ?{expression, total}}, in apply order
     *
     * @return list<SheetChangeData>
     *
     * @throws InvalidJournalEntryContent when a field is blank or a change is malformed
     */
    public static function checkedChanges(array $changes): array
    {
        $checked = [];
        foreach ($changes as $change) {
            $field = ContentData::string($change, 'field');
            if ('' === trim($field)) {
                throw InvalidJournalEntryContent::blank('A sheet field');
            }

            if (!\array_key_exists('dice', $change) || (null !== $change['dice'] && !\is_array($change['dice']))) {
                throw InvalidJournalEntryContent::malformed('dice', '{expression, total} or null');
            }

            $dice = $change['dice'];
            $checked[] = [
                'field' => $field,
                'before' => ContentData::int($change, 'before'),
                'after' => ContentData::int($change, 'after'),
                'dice' => null === $dice ? null : ['expression' => ContentData::string($dice, 'expression'), 'total' => ContentData::int($dice, 'total')],
            ];
        }

        return $checked;
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidJournalEntryContent
     */
    public static function fromArray(array $data): self
    {
        return self::of(ContentData::string($data, 'characterId'), ContentData::string($data, 'characterName'), ContentData::listOfArrays($data, 'changes'));
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
     * @return non-empty-list<SheetChangeData> in apply order
     */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * @return array{kind: string, characterId: string, characterName: string, changes: non-empty-list<SheetChangeData>}
     */
    public function toArray(): array
    {
        return [
            'kind' => self::KIND,
            'characterId' => $this->characterId,
            'characterName' => $this->characterName,
            'changes' => $this->changes,
        ];
    }
}
