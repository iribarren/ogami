<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystemNotFound;

final readonly class GetCardImageHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private CardImages $images,
        private ImageStore $store,
    ) {
    }

    /**
     * @throws GameSystemNotFound when the user has no such GameSystem
     * @throws CardImageNotFound  when neither the draft nor any release of the GameSystem uses an image with this hash
     */
    public function __invoke(GetCardImage $query): StoredImage
    {
        $id = $this->owned->get($query->ownerId, $query->key)->id();

        return ($this->images->isReadable($id, $query->key, $query->sha256) ? $this->store->get($query->sha256) : null)
            ?? throw CardImageNotFound::withHash($query->sha256);
    }
}
