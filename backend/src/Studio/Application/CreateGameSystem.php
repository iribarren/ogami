<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Creates a GameSystem draft owned by $ownerId: blank, or a copy of a published release ($sourceKey,
 * at $sourceVersion or its latest) under the new key and name. The key must be free of GameSystems
 * and releases alike (presets stay file-managed, ADR 0028). $id comes from a GameSystemIdGenerator.
 */
final readonly class CreateGameSystem implements Command
{
    public function __construct(
        public string $id,
        public string $ownerId,
        public string $key,
        public string $name,
        public ?string $description,
        public ?string $sourceKey,
        public ?int $sourceVersion,
    ) {
    }
}
