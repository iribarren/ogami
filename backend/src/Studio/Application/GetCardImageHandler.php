<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystemNotFound;

final readonly class GetCardImageHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private CardImagePins $pins,
        private ImageStore $store,
    ) {
    }

    /**
     * @throws GameSystemNotFound when the user has no such GameSystem
     * @throws CardImageNotFound  when the GameSystem does not use an image with this hash
     */
    public function __invoke(GetCardImage $query): StoredImage
    {
        $id = $this->owned->get($query->ownerId, $query->key)->id();

        return ($this->pins->isPinned($id, $query->sha256) ? $this->store->get($query->sha256) : null)
            ?? throw CardImageNotFound::withHash($query->sha256);
    }
}
