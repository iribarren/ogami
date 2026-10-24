<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Adds a Character to one of the player's campaigns. The caller generates the id (see
 * CharacterIdGenerator).
 */
final readonly class AddCharacter implements Command
{
    /**
     * @param list<string> $tags     release Tag keys of the pinned release
     * @param list<string> $freeTags
     * @param array<mixed> $values   sheet field key to value; a missing key takes the field default
     * @param ?string      $method   "roll" or "points" (ADR 0040), null when the sheet needs no choice
     */
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $characterId,
        public string $name,
        public array $tags,
        public array $freeTags,
        public array $values,
        public ?string $method = null,
    ) {
    }
}
