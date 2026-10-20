<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemRepository;

final readonly class IsGameSystemManagedInStudioHandler implements QueryHandler
{
    public function __construct(
        private GameSystemRepository $gameSystems,
    ) {
    }

    public function __invoke(IsGameSystemManagedInStudio $query): bool
    {
        return $this->gameSystems->ofKey($query->gameSystemKey) instanceof GameSystem;
    }
}
