<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

/**
 * Rebuilds a journal entry content from its canonical array shape, e.g. from persistence.
 */
final class JournalEntryContents
{
    private function __construct()
    {
    }

    /**
     * @param array<mixed> $data the shape returned by JournalEntryContent::toArray()
     *
     * @throws InvalidJournalEntryContent on an unknown kind or malformed data
     */
    public static function fromArray(array $data): JournalEntryContent
    {
        $kind = ContentData::string($data, 'kind');

        return match ($kind) {
            NoteContent::KIND => NoteContent::fromArray($data),
            RollContent::KIND => RollContent::fromArray($data),
            OracleTableContent::KIND => OracleTableContent::fromArray($data),
            LikelihoodContent::KIND => LikelihoodContent::fromArray($data),
            ChoiceContent::KIND => ChoiceContent::fromArray($data),
            default => throw InvalidJournalEntryContent::unknownKind($kind),
        };
    }
}
