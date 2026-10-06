<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

/**
 * Free text written by the player.
 */
final readonly class NoteContent implements JournalEntryContent
{
    public const string KIND = 'note';

    public const int MAX_LENGTH = 10_000;

    private function __construct(
        private string $text,
    ) {
    }

    /**
     * @throws InvalidJournalEntryContent when the trimmed text is blank or longer than 10,000 characters
     */
    public static function of(string $text): self
    {
        $text = trim($text);
        if ('' === $text) {
            throw InvalidJournalEntryContent::blankNote();
        }

        $length = mb_strlen($text);
        if ($length > self::MAX_LENGTH) {
            throw InvalidJournalEntryContent::noteTooLong(self::MAX_LENGTH, $length);
        }

        return new self($text);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidJournalEntryContent
     */
    public static function fromArray(array $data): self
    {
        return self::of(ContentData::string($data, 'text'));
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
     * @return array{kind: string, text: string}
     */
    public function toArray(): array
    {
        return ['kind' => self::KIND, 'text' => $this->text];
    }
}
