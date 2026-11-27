<?php

declare(strict_types=1);

namespace App\Tests\Support\Studio;

use App\Studio\Application\CardImagePins;
use App\Studio\Application\CardImages;
use App\Studio\Application\ImageStore;
use App\Studio\Application\StoredImage;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\Image\CardImagePin;
use App\Studio\Domain\Image\CardImageSlot;

/**
 * The image store and the draft pins kept in memory, with the same rule as the database: an image
 * is deleted only when no pin uses it.
 */
final class InMemoryCardImages implements ImageStore, CardImagePins
{
    /** @var array<string, StoredImage> by hash */
    private array $blobs = [];
    /** @var array<string, array<string, string>> hash by slot, by GameSystem id */
    private array $pins = [];

    public static function service(): CardImages
    {
        $images = new self();

        return new CardImages($images, $images);
    }

    public function put(StoredImage $image): void
    {
        $this->blobs[$image->image->sha256] = $image;
    }

    public function get(string $sha256): ?StoredImage
    {
        return $this->blobs[$sha256] ?? null;
    }

    public function removeIfUnreferenced(string $sha256): void
    {
        foreach ($this->pins as $slots) {
            if (\in_array($sha256, $slots, true)) {
                return;
            }
        }
        unset($this->blobs[$sha256]);
    }

    public function of(GameSystemId $gameSystem): array
    {
        $pins = [];
        foreach (CardImageSlot::cases() as $slot) {
            $sha256 = $this->pins[$gameSystem->toString()][$slot->value] ?? null;
            if (null !== $sha256) {
                $pins[] = new CardImagePin($slot, $this->blobs[$sha256]->image);
            }
        }

        return $pins;
    }

    public function pin(GameSystemId $gameSystem, CardImageSlot $slot, string $sha256): ?string
    {
        $previous = $this->pins[$gameSystem->toString()][$slot->value] ?? null;
        $this->pins[$gameSystem->toString()][$slot->value] = $sha256;

        return $previous;
    }

    public function unpin(GameSystemId $gameSystem, CardImageSlot $slot): ?string
    {
        $previous = $this->pins[$gameSystem->toString()][$slot->value] ?? null;
        unset($this->pins[$gameSystem->toString()][$slot->value]);

        return $previous;
    }

    public function isPinned(GameSystemId $gameSystem, string $sha256): bool
    {
        return \in_array($sha256, $this->pins[$gameSystem->toString()] ?? [], true);
    }

    /**
     * @return list<string> the hashes of every stored image
     */
    public function hashes(): array
    {
        return array_keys($this->blobs);
    }
}
