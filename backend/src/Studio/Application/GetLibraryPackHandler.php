<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\LibraryItems;
use App\Studio\Domain\GameSystem\LibrarySection;

final readonly class GetLibraryPackHandler implements QueryHandler
{
    public function __construct(
        private LibraryPacks $packs,
    ) {
    }

    /**
     * @throws LibraryPackNotFound when no library pack has the key
     */
    public function __invoke(GetLibraryPack $query): LibraryPackCatalog
    {
        $pack = $this->packs->get($query->key);
        $items = LibraryItems::of($pack->document());
        $metadata = $pack->document()['gameSystem'] ?? null;
        $description = \is_array($metadata) ? ($metadata['description'] ?? null) : null;

        $sections = array_map(static function (LibrarySection $section) use ($items): LibraryPackCatalogSection {
            $catalogued = [];
            foreach ($items->all() as $ref) {
                $located = $ref->section === $section ? $items->find($ref) : null;
                if (null === $located) {
                    continue;
                }

                $catalogued[] = new LibraryPackCatalogItem(
                    $ref->key,
                    LibraryItems::labelOf($located['item'], $ref->key),
                    LibrarySection::Oracles === $section ? ('oracles.likelihood' === $located['list'] ? 'likelihood' : 'table') : null,
                    $items->dependenciesOf($ref),
                );
            }

            return new LibraryPackCatalogSection($section, $catalogued);
        }, LibrarySection::cases());

        return new LibraryPackCatalog($pack->key(), $pack->name(), \is_string($description) ? $description : null, $pack->isOwnedBy($query->userId), $pack->updatedAt(), $sections);
    }
}
