<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\Image\CardImagePin;
use App\Studio\Domain\Image\CardImageSlot;

/**
 * Port: the images a GameSystem draft uses, one per slot. Kept apart from the draft document, so
 * pinning never bumps the draft's version (ADR 0053).
 */
interface CardImagePins
{
    /**
     * @return list<CardImagePin> in slot order; none for a GameSystem that never had images
     *
     * @throws \UnexpectedValueException when a stored row breaks the image rules
     */
    public function of(GameSystemId $gameSystem): array;

    /**
     * Pins the stored image in the slot, replacing the one there.
     *
     * @return string|null the hash of the image that was there
     */
    public function pin(GameSystemId $gameSystem, CardImageSlot $slot, string $sha256): ?string;

    /**
     * @return string|null the hash of the image that was there, if any
     */
    public function unpin(GameSystemId $gameSystem, CardImageSlot $slot): ?string;

    public function isPinned(GameSystemId $gameSystem, string $sha256): bool;
}
