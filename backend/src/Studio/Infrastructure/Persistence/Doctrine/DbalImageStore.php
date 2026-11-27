<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Persistence\Doctrine;

use App\Studio\Application\CardImagePins;
use App\Studio\Application\ImageStore;
use App\Studio\Application\StoredImage;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\Image\CardImage;
use App\Studio\Domain\Image\CardImagePin;
use App\Studio\Domain\Image\CardImageSlot;
use App\Studio\Domain\Image\ImageRefused;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Card images as `bytea` rows keyed by the hash of their bytes, and the draft's pins in a table of
 * their own (ADR 0053). Plain SQL: the tables are not part of the ORM's schema.
 */
final readonly class DbalImageStore implements ImageStore, CardImagePins
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function put(StoredImage $image): void
    {
        $this->connection->executeStatement(
            'INSERT INTO studio_image (sha256, media_type, width, height, byte_size, data, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW()) ON CONFLICT (sha256) DO NOTHING',
            [$image->image->sha256, $image->image->mediaType, $image->image->width, $image->image->height, $image->image->byteSize, $image->bytes],
            [4 => ParameterType::INTEGER, 5 => ParameterType::LARGE_OBJECT],
        );
    }

    public function get(string $sha256): ?StoredImage
    {
        $row = $this->connection->fetchAssociative('SELECT sha256, media_type, width, height, byte_size, data FROM studio_image WHERE sha256 = ?', [$sha256]);

        return false === $row ? null : $this->stored($row);
    }

    public function removeIfUnreferenced(string $sha256): void
    {
        $this->connection->executeStatement(
            'DELETE FROM studio_image WHERE sha256 = ? AND NOT EXISTS (SELECT 1 FROM studio_card_image_pin WHERE sha256 = ?)',
            [$sha256, $sha256],
        );
    }

    public function of(GameSystemId $gameSystem): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.slot, i.sha256, i.media_type, i.width, i.height, i.byte_size, i.data FROM studio_card_image_pin p JOIN studio_image i ON i.sha256 = p.sha256 WHERE p.game_system_id = ? ORDER BY p.slot',
            [$gameSystem->toString()],
        );

        return array_map(
            fn (array $row): CardImagePin => new CardImagePin($this->slot($row['slot']), $this->stored($row)->image),
            $rows,
        );
    }

    public function pin(GameSystemId $gameSystem, CardImageSlot $slot, string $sha256): ?string
    {
        $previous = $this->current($gameSystem, $slot);
        $this->connection->executeStatement(
            'INSERT INTO studio_card_image_pin (game_system_id, slot, sha256) VALUES (?, ?, ?) ON CONFLICT (game_system_id, slot) DO UPDATE SET sha256 = EXCLUDED.sha256',
            [$gameSystem->toString(), $slot->value, $sha256],
        );

        return $previous;
    }

    public function unpin(GameSystemId $gameSystem, CardImageSlot $slot): ?string
    {
        $previous = $this->current($gameSystem, $slot);
        $this->connection->executeStatement('DELETE FROM studio_card_image_pin WHERE game_system_id = ? AND slot = ?', [$gameSystem->toString(), $slot->value]);

        return $previous;
    }

    public function isPinned(GameSystemId $gameSystem, string $sha256): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM studio_card_image_pin WHERE game_system_id = ? AND sha256 = ?', [$gameSystem->toString(), $sha256]);
    }

    /**
     * Locks the slot's row so two uploads in the slot cannot both delete the same previous image.
     */
    private function current(GameSystemId $gameSystem, CardImageSlot $slot): ?string
    {
        $sha256 = $this->connection->fetchOne('SELECT sha256 FROM studio_card_image_pin WHERE game_system_id = ? AND slot = ? FOR UPDATE', [$gameSystem->toString(), $slot->value]);

        return \is_string($sha256) ? $sha256 : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stored(array $row): StoredImage
    {
        $data = $row['data'];
        $bytes = \is_resource($data) ? stream_get_contents($data) : $data;
        if (!\is_string($bytes) || !\is_string($row['sha256']) || !\is_string($row['media_type']) || !is_numeric($row['width']) || !is_numeric($row['height']) || !is_numeric($row['byte_size'])) {
            throw new \UnexpectedValueException('A stored image row is malformed.');
        }

        try {
            $image = CardImage::of(trim($row['sha256']), $row['media_type'], (int) $row['width'], (int) $row['height'], (int) $row['byte_size']);
        } catch (ImageRefused|\InvalidArgumentException $refusal) {
            throw new \UnexpectedValueException(\sprintf('The stored image "%s" breaks the image rules: %s', (string) $row['sha256'], $refusal->getMessage()), 0, $refusal);
        }
        if (\strlen($bytes) !== $image->byteSize || !hash_equals($image->sha256, hash('sha256', $bytes))) {
            throw new \UnexpectedValueException(\sprintf('The stored image "%s" does not match its bytes.', $image->sha256));
        }

        return new StoredImage($image, $bytes);
    }

    private function slot(mixed $value): CardImageSlot
    {
        return \is_string($value) ? CardImageSlot::tryFrom($value) ?? throw new \UnexpectedValueException(\sprintf('A stored pin has an unknown slot "%s".', $value)) : throw new \UnexpectedValueException('A stored pin has no slot.');
    }
}
