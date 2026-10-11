<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * What a condition step counts instead of a Tracker value: the open NPCs or Threads carrying all
 * the listed release Tag keys (any when the list is empty).
 */
final readonly class ItemCount
{
    /**
     * @param list<string> $tags Tag keys of the release
     */
    public function __construct(
        public ItemKind $of,
        public array $tags = [],
    ) {
    }
}
