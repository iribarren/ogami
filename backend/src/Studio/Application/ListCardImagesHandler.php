<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Image\CardImagePin;

final readonly class ListCardImagesHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private CardImagePins $pins,
    ) {
    }

    /**
     * @return list<CardImagePin>
     *
     * @throws GameSystemNotFound when the user has no such GameSystem
     */
    public function __invoke(ListCardImages $query): array
    {
        return $this->pins->of($this->owned->get($query->ownerId, $query->key)->id());
    }
}
