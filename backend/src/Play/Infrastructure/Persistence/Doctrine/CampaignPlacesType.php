<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Persistence\Doctrine;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Lists\InvalidPlace;
use App\Play\Domain\Campaign\Lists\ItemStatus;
use App\Play\Domain\Campaign\Lists\Place;
use App\Play\Domain\Campaign\Lists\PlaceId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\JsonType;

/**
 * Maps a campaign's Places to one JSON column (JSONB with the "jsonb" option). They are immutable
 * values identified by their id within the campaign, always loaded and saved with it (ADR 0007).
 *
 * Stored shape: [{id, name, notes, tags, freeTags, weight, status}], in creation order; `status` is
 * "open" or "closed". The column defaults to an empty list, which is what a campaign stored before
 * Places reads as. Reading checks the shape, the 500 limit, distinct ids and the rules Place::of() holds (not whether the Tags
 * are in the release, which only the pinned release can tell); stored data that breaks either
 * fails closed with a clear error instead of loading a campaign in a state the domain refuses.
 */
final class CampaignPlacesType extends JsonType
{
    public const string NAME = 'play_campaign_places';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_array($value)) {
            throw InvalidType::new($value, self::NAME, ['array', 'null']);
        }

        $items = [];
        foreach ($value as $place) {
            if (!$place instanceof Place) {
                throw InvalidType::new($place, self::NAME, [Place::class]);
            }

            $items[] = [
                'id' => $place->id()->toString(),
                'name' => $place->name(),
                'notes' => $place->notes(),
                'tags' => $place->tags(),
                'freeTags' => $place->freeTags(),
                'weight' => $place->weight(),
                'status' => $place->status()->value,
            ];
        }

        return parent::convertToDatabaseValue($items, $platform);
    }

    /**
     * @return list<Place>|null
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        $data = parent::convertToPHPValue($value, $platform);
        if (null === $data) {
            return null;
        }

        if (!\is_array($data) || !array_is_list($data)) {
            throw $this->malformed('places', 'a list');
        }

        if (\count($data) > Campaign::MAX_PLACES) {
            throw ValueNotConvertible::new('places', self::NAME, \sprintf('Stored campaign places: at most %d Places are allowed, got %d.', Campaign::MAX_PLACES, \count($data)));
        }

        $items = array_map($this->place(...), $data);
        $ids = [];
        foreach ($items as $item) {
            $id = $item->id()->toString();
            if (isset($ids[$id])) {
                throw ValueNotConvertible::new('places', self::NAME, \sprintf('Stored campaign places: id "%s" is listed twice.', $id));
            }

            $ids[$id] = true;
        }

        return $items;
    }

    private function place(mixed $data): Place
    {
        $status = $this->string($data, 'status');

        try {
            return Place::of(
                PlaceId::fromString($this->string($data, 'id')),
                $this->string($data, 'name'),
                $this->optionalString($data, 'notes'),
                $this->strings($data, 'tags'),
                $this->strings($data, 'freeTags'),
                $this->int($data, 'weight'),
                ItemStatus::tryFrom($status) ?? throw $this->malformed('status', 'one of "open", "closed"'),
            );
        } catch (InvalidPlace $exception) {
            throw ValueNotConvertible::new(Place::class, self::NAME, 'Stored campaign places: '.$exception->getMessage());
        }
    }

    private function field(mixed $data, string $field): mixed
    {
        return \is_array($data) ? ($data[$field] ?? null) : null;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $data, string $field): array
    {
        $value = $this->field($data, $field);
        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->malformed($field, 'a list of strings');
        }

        $strings = [];
        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw $this->malformed($field, 'a list of strings');
            }

            $strings[] = $item;
        }

        return $strings;
    }

    private function optionalString(mixed $data, string $field): ?string
    {
        return null === $this->field($data, $field) ? null : $this->string($data, $field);
    }

    private function int(mixed $data, string $field): int
    {
        $value = $this->field($data, $field);
        if (!\is_int($value)) {
            throw $this->malformed($field, 'an integer');
        }

        return $value;
    }

    private function string(mixed $data, string $field): string
    {
        $value = $this->field($data, $field);
        if (!\is_string($value)) {
            throw $this->malformed($field, 'a string');
        }

        return $value;
    }

    private function malformed(string $field, string $expected): ValueNotConvertible
    {
        return ValueNotConvertible::new($field, self::NAME, \sprintf('Stored campaign places: "%s" must be %s.', $field, $expected));
    }
}
