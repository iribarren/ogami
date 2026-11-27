<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\Image\CardImageSlot;
use App\Studio\Domain\Release\ReleaseId;

/**
 * Keeps a GameSystem's pins, its releases' pins and the stored images they point to in step: an image
 * nobody pins, in the draft or in any release, is deleted. Callers run it inside a command, so the
 * pins and the blobs are written all or nothing.
 */
final readonly class CardImages
{
    public function __construct(
        private ImageStore $store,
        private CardImagePins $pins,
        private ReleaseCardImagePins $releasePins,
    ) {
    }

    /**
     * Last write wins: the old image of the slot is deleted when nothing else uses it.
     */
    public function replace(GameSystemId $gameSystem, CardImageSlot $slot, StoredImage $image): void
    {
        $this->pins->lock($gameSystem);
        $this->store->put($image);
        $previous = $this->pins->pin($gameSystem, $slot, $image->image->sha256);
        $this->releaseIfChanged($previous, $image->image->sha256);
    }

    public function remove(GameSystemId $gameSystem, CardImageSlot $slot): void
    {
        $this->pins->lock($gameSystem);
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

    /**
     * The draft's images as the hash of each, by slot value, in slot order. Takes the GameSystem lock,
     * so an upload cannot replace an image between this read and the release pinning it.
     *
     * @return array<string, string>
     */
    public function draftHashes(GameSystemId $gameSystem): array
    {
        $this->pins->lock($gameSystem);
        $hashes = [];
        foreach ($this->pins->of($gameSystem) as $pin) {
            $hashes[$pin->slot->value] = $pin->image->sha256;
        }
        ksort($hashes);

        return $hashes;
    }

    /**
     * @return array<string, string> as draftHashes(), for a release; empty for one published without images
     */
    public function releaseHashes(ReleaseId $release): array
    {
        $hashes = $this->releasePins->hashesOf($release);
        ksort($hashes);

        return $hashes;
    }

    /**
     * Pins the images the draft had when the release was added. Never changed afterwards.
     *
     * @param array<string, string> $hashes from draftHashes()
     */
    public function pinToRelease(ReleaseId $release, array $hashes): void
    {
        $this->releasePins->pinAll($release, $hashes);
    }

    /**
     * Makes the draft's images those of the release (ADR 0053): a slot the release left empty is
     * emptied, and an image nobody pins any more is deleted.
     */
    public function restoreDraftFrom(GameSystemId $gameSystem, ReleaseId $release): void
    {
        $this->pins->lock($gameSystem);
        $target = $this->releasePins->hashesOf($release);
        foreach (CardImageSlot::cases() as $slot) {
            $sha256 = $target[$slot->value] ?? null;
            $previous = null === $sha256 ? $this->pins->unpin($gameSystem, $slot) : $this->pins->pin($gameSystem, $slot, $sha256);
            $this->releaseIfChanged($previous, $sha256);
        }
    }

    /**
     * Whether the owner may read this image: the draft uses it, or a release of the GameSystem pinned it.
     */
    public function isReadable(GameSystemId $gameSystem, string $gameSystemKey, string $sha256): bool
    {
        return $this->pins->isPinned($gameSystem, $sha256) || $this->releasePins->isPinnedByReleaseOf($gameSystemKey, $sha256);
    }

    private function releaseIfChanged(?string $previous, ?string $current): void
    {
        if (null !== $previous && $previous !== $current) {
            $this->store->removeIfUnreferenced($previous);
        }
    }
}
