<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\Campaign\Lists\Thread;

/**
 * A Thread of a campaign: its release Tag keys, free tags, weight and status ("open" or "closed").
 */
final readonly class ThreadView
{
    /**
     * @param list<string> $tags     release Tag keys
     * @param list<string> $freeTags
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $tags,
        public array $freeTags,
        public int $weight,
        public string $status,
    ) {
    }

    public static function of(Thread $thread): self
    {
        return new self($thread->id()->toString(), $thread->name(), $thread->tags(), $thread->freeTags(), $thread->weight(), $thread->status()->value);
    }
}
