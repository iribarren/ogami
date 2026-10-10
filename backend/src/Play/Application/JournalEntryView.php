<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Journal\JournalEntry;

/**
 * One entry of a campaign's journal, with the session and scene it was recorded in.
 */
final readonly class JournalEntryView
{
    /**
     * @param string                                              $kind     "note", "roll", "oracle-table", "likelihood" or "choice"
     * @param array<string, mixed>                                $content  the content's canonical shape, "kind" included
     * @param ?array{key: string, title: string, prompt: ?string} $flowStep the Flow step that recorded the entry, null when the player did
     */
    public function __construct(
        public string $id,
        public int $sessionNumber,
        public int $sceneNumber,
        public \DateTimeImmutable $recordedAt,
        public string $kind,
        public array $content,
        public ?array $flowStep = null,
    ) {
    }

    public static function of(JournalEntry $entry): self
    {
        return new self(
            $entry->id()->toString(),
            $entry->sessionNumber(),
            $entry->sceneNumber(),
            $entry->recordedAt(),
            $entry->content()->kind(),
            $entry->content()->toArray(),
            $entry->flowStep()?->toArray(),
        );
    }
}
