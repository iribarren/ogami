<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Creates a blank library pack owned by $ownerId (ADR 0033). Its key follows the GameSystem key
 * rules: free of GameSystems, packs and releases alike. $id comes from a GameSystemIdGenerator.
 */
final readonly class CreateLibraryPack implements Command
{
    public function __construct(
        public string $id,
        public string $ownerId,
        public string $key,
        public string $name,
        public ?string $description,
    ) {
    }
}
