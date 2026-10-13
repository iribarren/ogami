<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Persistence\Doctrine;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Lists\InvalidNpc;
use App\Play\Domain\Campaign\Lists\NpcId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\JsonType;

/**
 * Maps a campaign's Characters to one JSON column (JSONB with the "jsonb" option). They are
 * immutable values identified by their id within the campaign, always loaded and saved with it
 * (ADR 0007, ADR 0022).
 *
 * Stored shape: [{id, name, tags, freeTags, values, promotedFrom}], in creation order; `values` maps
 * a sheet field key to an integer, a string, a list of strings or {current, max}; `promotedFrom` is
 * the id of the NPC it came from or null. The column defaults to an empty list, which is what a
 * campaign stored before Characters reads as. Reading checks the shape, the 100 limit, distinct ids
 * and the rules Character::of() holds (not whether the Tags are in the release or the values fit
 * its sheet, which only the pinned release can tell); stored data that breaks either fails closed
 * with a clear error instead of loading a campaign in a state the domain refuses.
 */
final class CampaignCharactersType extends JsonType
{
    public const string NAME = 'play_campaign_characters';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_array($value)) {
            throw InvalidType::new($value, self::NAME, ['array', 'null']);
        }

        $items = [];
        foreach ($value as $character) {
            if (!$character instanceof Character) {
                throw InvalidType::new($character, self::NAME, [Character::class]);
            }

            $items[] = [
                'id' => $character->id()->toString(),
                'name' => $character->name(),
                'tags' => $character->tags(),
                'freeTags' => $character->freeTags(),
                'values' => (object) $character->values(),
                'promotedFrom' => $character->promotedFrom()?->toString(),
            ];
        }

        return parent::convertToDatabaseValue($items, $platform);
    }

    /**
     * @return list<Character>|null
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        $data = parent::convertToPHPValue($value, $platform);
        if (null === $data) {
            return null;
        }

        if (!\is_array($data) || !array_is_list($data)) {
            throw $this->malformed('characters', 'a list');
        }

        if (\count($data) > Campaign::MAX_CHARACTERS) {
            throw ValueNotConvertible::new('characters', self::NAME, \sprintf('Stored campaign characters: at most %d Characters are allowed, got %d.', Campaign::MAX_CHARACTERS, \count($data)));
        }

        $items = array_map($this->character(...), $data);
        $ids = [];
        foreach ($items as $item) {
            $id = $item->id()->toString();
            if (isset($ids[$id])) {
                throw ValueNotConvertible::new('characters', self::NAME, \sprintf('Stored campaign characters: id "%s" is listed twice.', $id));
            }

            $ids[$id] = true;
        }

        return $items;
    }

    private function character(mixed $data): Character
    {
        $values = $this->field($data, 'values');
        if (!\is_array($values)) {
            throw $this->malformed('values', 'an object');
        }

        $promotedFrom = $this->optionalString($data, 'promotedFrom');

        try {
            return Character::of(
                CharacterId::fromString($this->string($data, 'id')),
                $this->string($data, 'name'),
                $this->strings($data, 'tags'),
                $this->strings($data, 'freeTags'),
                $values,
                null === $promotedFrom ? null : NpcId::fromString($promotedFrom),
            );
        } catch (InvalidCharacter|InvalidNpc $exception) {
            throw ValueNotConvertible::new(Character::class, self::NAME, 'Stored campaign characters: '.$exception->getMessage());
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

    private function string(mixed $data, string $field): string
    {
        $value = $this->field($data, $field);
        if (!\is_string($value)) {
            throw $this->malformed($field, 'a string');
        }

        return $value;
    }

    private function optionalString(mixed $data, string $field): ?string
    {
        return null === $this->field($data, $field) ? null : $this->string($data, $field);
    }

    private function malformed(string $field, string $expected): ValueNotConvertible
    {
        return ValueNotConvertible::new($field, self::NAME, \sprintf('Stored campaign characters: "%s" must be %s.', $field, $expected));
    }
}
