<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\Image\CardImageSlot;

/**
 * Keeps a GameSystem's pins and the stored images they point to in step: an image nobody pins is
 * deleted. Callers run it inside a command, so the pin and the blob are written all or nothing.
 */
final readonly class CardImages
{
    public function __construct(
        private ImageStore $store,
        private CardImagePins $pins,
    ) {
    }

    /**
     * Last write wins: the old image of the slot is deleted when nothing else uses it.
     */
    public function replace(GameSystemId $gameSystem, CardImageSlot $slot, StoredImage $image): void
    {
        $this->store->put($image);
        $previous = $this->pins->pin($gameSystem, $slot, $image->image->sha256);
        $this->releaseIfChanged($previous, $image->image->sha256);
    }

    public function remove(GameSystemId $gameSystem, CardImageSlot $slot): void
    {
        $this->releaseIfChanged($this->pins->unpin($gameSystem, $slot), null);
    }

    /**
     * Empties both slots of a GameSystem that is about to be deleted, and deletes the images nobody
     * else uses. The database would drop the pins with the row, but not the images.
     */
    public function discard(GameSystemId $gameSystem): void
    {
        foreach (CardImageSlot::cases() as $slot) {
            $this->remove($gameSystem, $slot);
        }
    }

    private function releaseIfChanged(?string $previous, ?string $current): void
    {
        if (null !== $previous && $previous !== $current) {
            $this->store->removeIfUnreferenced($previous);
        }
    }
}
