<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

/**
 * Free text written by the player, with the results of its scene it attaches: the ids of
 * journal entries, in the order the player picked them. The note references the results, never the
 * other way round; which entries may be attached is checked where the note is recorded.
 */
final readonly class NoteContent implements JournalEntryContent
{
    public const string KIND = 'note';

    public const int MAX_LENGTH = 10_000;

    public const int MAX_ATTACHMENTS = 20;

    /**
     * @param list<string> $attachments
     */
    private function __construct(
        private string $text,
        private array $attachments,
    ) {
    }

    /**
     * @param list<string> $attachments ids of the attached journal entries, in the order picked
     *
     * @throws InvalidJournalEntryContent when the trimmed text is blank or longer than 10,000 characters
     * @throws InvalidNoteAttachment      when an attachment id is blank or given twice, or there are more than 20
     */
    public static function of(string $text, array $attachments = []): self
    {
        $text = trim($text);
        if ('' === $text) {
            throw InvalidJournalEntryContent::blankNote();
        }

        $length = mb_strlen($text);
        if ($length > self::MAX_LENGTH) {
            throw InvalidJournalEntryContent::noteTooLong(self::MAX_LENGTH, $length);
        }

        if (\count($attachments) > self::MAX_ATTACHMENTS) {
            throw InvalidNoteAttachment::tooMany(self::MAX_ATTACHMENTS, \count($attachments));
        }

        $seen = [];
        foreach ($attachments as $attachment) {
            if ('' === trim($attachment)) {
                throw InvalidNoteAttachment::blankId();
            }

            if (isset($seen[$attachment])) {
                throw InvalidNoteAttachment::duplicate($attachment);
            }

            $seen[$attachment] = true;
        }

        return new self($text, $attachments);
    }

    /**
     * Notes stored before results could be attached have no `attachments`: they read as none.
     * Stored attachments are trusted like any stored content: only their shape is checked.
     *
     * @param array<mixed> $data
     *
     * @throws InvalidJournalEntryContent
     */
    public static function fromArray(array $data): self
    {
        try {
            return self::of(
                ContentData::string($data, 'text'),
                \array_key_exists('attachments', $data) ? ContentData::listOfStrings($data, 'attachments') : [],
            );
        } catch (InvalidNoteAttachment) {
            throw InvalidJournalEntryContent::malformed('attachments', 'a list of at most 20 distinct, non-blank entry ids');
        }
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * The ids of the attached journal entries, in the order the player picked them.
     *
     * @return list<string>
     */
    public function attachments(): array
    {
        return $this->attachments;
    }

    /**
     * @return array{kind: string, text: string, attachments: list<string>}
     */
    public function toArray(): array
    {
        return ['kind' => self::KIND, 'text' => $this->text, 'attachments' => $this->attachments];
    }
}
