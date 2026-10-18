<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\LibraryItems;
use App\Studio\Domain\Release\ReleaseContent;

final readonly class GetGameSystemLibraryImportsHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private LibraryPacks $packs,
    ) {
    }

    /**
     * @return list<LibraryImportView> in the order imported
     *
     * @throws GameSystemNotFound when the user has no such GameSystem
     */
    public function __invoke(GetGameSystemLibraryImports $query): array
    {
        $gameSystem = $this->owned->get($query->ownerId, $query->key);
        $draft = LibraryItems::of($gameSystem->document());
        /** @var array<string, array{GameSystem, LibraryItems}|null> $packs each pack is loaded once */
        $packs = [];
        $views = [];
        foreach ($gameSystem->libraryImports() as $import) {
            if (!\array_key_exists($import->pack, $packs)) {
                $pack = $this->packs->find($import->pack);
                $packs[$import->pack] = $pack instanceof GameSystem ? [$pack, LibraryItems::of($pack->document())] : null;
            }

            $source = null === $packs[$import->pack] ? null : $packs[$import->pack][1]->find($import->ref());
            $own = $draft->find($import->ref());

            $views[] = new LibraryImportView(
                $import->section,
                $import->key,
                LibraryItems::labelOf($own['item'] ?? null, $import->key),
                null === $packs[$import->pack] ? null : ['key' => $import->pack, 'name' => $packs[$import->pack][0]->name()],
                match (true) {
                    null === $source => LibraryImportStatus::Removed,
                    ReleaseContent::itemHash($source['item']) !== $import->hash => LibraryImportStatus::Changed,
                    default => LibraryImportStatus::Current,
                },
                null !== $own && ReleaseContent::itemHash($own['item']) !== $import->hash,
            );
        }

        return $views;
    }
}
