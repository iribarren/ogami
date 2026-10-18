<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystemNotFound;

final readonly class GetGameSystemLibraryImportsHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private LibraryImportStandings $standings,
    ) {
    }

    /**
     * @throws GameSystemNotFound when the user has no such GameSystem
     */
    public function __invoke(GetGameSystemLibraryImports $query): LibraryImportsView
    {
        $gameSystem = $this->owned->get($query->ownerId, $query->key);

        return new LibraryImportsView($gameSystem->version(), $this->standings->of($gameSystem));
    }
}
