<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Replaces the name, description, credits and theme of the owner's GameSystem draft as a whole.
 */
final readonly class EditGameSystemMetadata implements Command
{
    /**
     * @param array<mixed>|null $credits {notice, license?, url?, badge?}
     */
    public function __construct(
        public string $ownerId,
        public string $key,
        public string $name,
        public ?string $description,
        public ?array $credits,
        public ?string $theme,
    ) {
    }
}
