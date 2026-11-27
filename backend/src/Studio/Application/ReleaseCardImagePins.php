<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\Image\CardImagePin;
use App\Studio\Domain\Release\ReleaseId;

/**
 * Port: the images a release pinned when it was published, one per slot. Written once, with the
 * release, and never changed; a release published before images existed has none (ADR 0053).
 */
interface ReleaseCardImagePins
{
    /**
     * Pins the images of a release that was just added, in the same transaction.
     *
     * @param array<string, string> $hashBySlot the sha256 of each pinned image, by slot value; every image must be stored
     */
    public function pinAll(ReleaseId $release, array $hashBySlot): void;

    /**
     * @return array<string, string> the sha256 of each pinned image by slot value, in slot order; empty for a legacy release
     */
    public function hashesOf(ReleaseId $release): array;

    /**
     * @return list<CardImagePin> in slot order; none for a legacy release
     *
     * @throws \UnexpectedValueException when a stored row breaks the image rules
     */
    public function pinsOf(ReleaseId $release): array;

    /**
     * Whether any release of the GameSystem with this key pinned the image.
     */
    public function isPinnedByReleaseOf(string $gameSystemKey, string $sha256): bool;
}
