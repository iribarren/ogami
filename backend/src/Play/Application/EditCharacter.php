<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Replaces the name, Tags, free tags and values of a Character of one of the player's campaigns: a
 * value not given goes back to its field default.
 */
final readonly class EditCharacter implements Command
{
    /**
     * @param list<string> $tags     release Tag keys of the pinned release
     * @param list<string> $freeTags
     * @param array<mixed> $values   sheet field key to value
     */
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $characterId,
        public string $name,
        public array $tags,
        public array $freeTags,
        public array $values,
    ) {
    }
}
