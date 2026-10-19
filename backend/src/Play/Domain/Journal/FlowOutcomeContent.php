<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

/**
 * What the Flow decided on its own while one command ran, told afterwards: the label of the band
 * taken, a Tracker that changed (from and to), a Scene Type switch, the Scene Type forced for the
 * next scene and the end of the phase, in apply order. Names are those of the pinned release when
 * the Flow decided. One entry per command, recorded right after the step's own entry or, when the
 * command has none, in the scene the command left current; when no scene is current, nothing is
 * recorded and the notes are dropped (the journal belongs to a scene).
 *
 * @phpstan-type FlowOutcomeNote array{kind: 'band', label: string}|array{kind: 'tracker', key: string, name: string, from: int, to: int}|array{kind: 'switch', from: string, to: string}|array{kind: 'nextScene', sceneType: string}|array{kind: 'phaseEnd', phase: string}
 */
final readonly class FlowOutcomeContent implements JournalEntryContent
{
    public const string KIND = 'flowOutcome';

    /**
     * @param non-empty-list<FlowOutcomeNote> $notes
     */
    private function __construct(private array $notes)
    {
    }

    /**
     * @param list<array<mixed>> $notes each a kind and its fields, in apply order
     *
     * @throws InvalidJournalEntryContent when there is no note, a kind is unknown or a field is missing, blank or of another type
     */
    public static function of(array $notes): self
    {
        $checked = [];
        foreach ($notes as $note) {
            $checked[] = self::note($note);
        }

        if ([] === $checked) {
            throw InvalidJournalEntryContent::malformed('notes', 'a list of at least one note');
        }

        return new self($checked);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidJournalEntryContent
     */
    public static function fromArray(array $data): self
    {
        return self::of(ContentData::listOfArrays($data, 'notes'));
    }

    public function kind(): string
    {
        return self::KIND;
    }

    /**
     * @return non-empty-list<FlowOutcomeNote> in apply order
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * @return array{kind: string, notes: non-empty-list<FlowOutcomeNote>}
     */
    public function toArray(): array
    {
        return ['kind' => self::KIND, 'notes' => $this->notes];
    }

    /**
     * @param array<mixed> $note
     *
     * @return FlowOutcomeNote
     */
    private static function note(array $note): array
    {
        return match (ContentData::string($note, 'kind')) {
            'band' => ['kind' => 'band', 'label' => self::text($note, 'label')],
            'tracker' => ['kind' => 'tracker', 'key' => self::text($note, 'key'), 'name' => self::text($note, 'name'), 'from' => ContentData::int($note, 'from'), 'to' => ContentData::int($note, 'to')],
            'switch' => ['kind' => 'switch', 'from' => self::text($note, 'from'), 'to' => self::text($note, 'to')],
            'nextScene' => ['kind' => 'nextScene', 'sceneType' => self::text($note, 'sceneType')],
            'phaseEnd' => ['kind' => 'phaseEnd', 'phase' => self::text($note, 'phase')],
            default => throw InvalidJournalEntryContent::unknownKind('flowOutcome note '.ContentData::string($note, 'kind')),
        };
    }

    /**
     * @param array<mixed> $note
     */
    private static function text(array $note, string $field): string
    {
        $text = ContentData::string($note, $field);
        if ('' === trim($text)) {
            throw InvalidJournalEntryContent::blank('A flow note '.$field);
        }

        return $text;
    }
}
