<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\LibraryItemNotFound;
use App\Studio\Domain\GameSystem\LibraryItemRef;
use App\Studio\Domain\GameSystem\LibraryPackRefuses;
use App\Studio\Domain\Release\InvalidReleaseContent;

final readonly class PreviewLibraryImportHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private LibraryPacks $packs,
    ) {
    }

    /**
     * @throws InvalidReleaseContent when the items are malformed (naming the path) or the draft is schema version 1
     * @throws GameSystemNotFound    when the user has no such GameSystem
     * @throws LibraryPackNotFound   when no library pack has the key
     * @throws LibraryPackRefuses    when the target is a library pack
     * @throws LibraryItemNotFound   when the pack has none of an item
     */
    public function __invoke(PreviewLibraryImport $query): LibraryImportPreview
    {
        $items = LibraryItemRef::listFromArray($query->items);
        $plan = $this->owned->get($query->ownerId, $query->key)->planLibraryImport($this->packs->get($query->pack), $items);

        return new LibraryImportPreview(
            $this->arrays($plan->imports),
            $this->arrays($plan->overrides),
            $this->arrays($plan->conflicts),
            null === $plan->errorPath ? null : ['path' => $plan->errorPath, 'message' => $plan->errorMessage ?? ''],
        );
    }

    /**
     * @param list<LibraryItemRef> $refs
     *
     * @return list<array{section: string, key: string}>
     */
    private function arrays(array $refs): array
    {
        return array_map(static fn (LibraryItemRef $ref): array => $ref->toArray(), $refs);
    }
}
