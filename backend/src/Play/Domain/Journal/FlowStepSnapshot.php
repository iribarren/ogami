<?php

declare(strict_types=1);

namespace App\Play\Domain\Journal;

/**
 * The Flow step that recorded a journal entry, as the player saw it: its key, title and prompt
 * with placeholders already rendered. The entry keeps it as it was, whatever the FlowRun does next.
 */
final readonly class FlowStepSnapshot
{
    private function __construct(
        public string $key,
        public string $title,
        public ?string $prompt,
    ) {
    }

    /**
     * @throws InvalidJournalEntryContent when the key or the title is blank
     */
    public static function of(string $key, string $title, ?string $prompt): self
    {
        foreach (['A Flow step key' => $key, 'A Flow step title' => $title] as $what => $value) {
            if ('' === trim($value)) {
                throw InvalidJournalEntryContent::blank($what);
            }
        }

        return new self($key, $title, $prompt);
    }

    /**
     * @param array<mixed> $data the shape returned by toArray()
     *
     * @throws InvalidJournalEntryContent on malformed data
     */
    public static function fromArray(array $data): self
    {
        return self::of(ContentData::string($data, 'key'), ContentData::string($data, 'title'), ContentData::nullableString($data, 'prompt'));
    }

    /**
     * @return array{key: string, title: string, prompt: ?string} the canonical stored shape
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'title' => $this->title, 'prompt' => $this->prompt];
    }
}
