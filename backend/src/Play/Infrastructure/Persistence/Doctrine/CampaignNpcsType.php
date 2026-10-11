<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Persistence\Doctrine;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Lists\InvalidNpc;
use App\Play\Domain\Campaign\Lists\ItemStatus;
use App\Play\Domain\Campaign\Lists\Npc;
use App\Play\Domain\Campaign\Lists\NpcId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\JsonType;

/**
 * Maps a campaign's NPCs to one JSON column (JSONB with the "jsonb" option). They are immutable
 * values identified by their id within the campaign, always loaded and saved with it (ADR 0007).
 *
 * Stored shape: [{id, name, agenda, tags, freeTags, weight, status}], in creation order; `status` is
 * "open" or "closed". The column defaults to an empty list, which is what a campaign stored before
 * NPCs reads as. Reading checks the shape, the 500 limit, distinct ids and the rules Npc::of() holds (not whether the Tags
 * are in the release, which only the pinned release can tell); stored data that breaks either
 * fails closed with a clear error instead of loading a campaign in a state the domain refuses.
 */
final class CampaignNpcsType extends JsonType
{
    public const string NAME = 'play_campaign_npcs';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_array($value)) {
            throw InvalidType::new($value, self::NAME, ['array', 'null']);
        }

        $items = [];
        foreach ($value as $npc) {
            if (!$npc instanceof Npc) {
                throw InvalidType::new($npc, self::NAME, [Npc::class]);
            }

            $items[] = [
                'id' => $npc->id()->toString(),
                'name' => $npc->name(),
                'agenda' => $npc->agenda(),
                'tags' => $npc->tags(),
                'freeTags' => $npc->freeTags(),
                'weight' => $npc->weight(),
                'status' => $npc->status()->value,
            ];
        }

        return parent::convertToDatabaseValue($items, $platform);
    }

    /**
     * @return list<Npc>|null
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        $data = parent::convertToPHPValue($value, $platform);
        if (null === $data) {
            return null;
        }

        if (!\is_array($data) || !array_is_list($data)) {
            throw $this->malformed('npcs', 'a list');
        }

        if (\count($data) > Campaign::MAX_NPCS) {
            throw ValueNotConvertible::new('npcs', self::NAME, \sprintf('Stored campaign npcs: at most %d NPCs are allowed, got %d.', Campaign::MAX_NPCS, \count($data)));
        }

        $items = array_map($this->npc(...), $data);
        $ids = [];
        foreach ($items as $item) {
            $id = $item->id()->toString();
            if (isset($ids[$id])) {
                throw ValueNotConvertible::new('npcs', self::NAME, \sprintf('Stored campaign npcs: id "%s" is listed twice.', $id));
            }

            $ids[$id] = true;
        }

        return $items;
    }

    private function npc(mixed $data): Npc
    {
        $status = $this->string($data, 'status');

        try {
            return Npc::of(
                NpcId::fromString($this->string($data, 'id')),
                $this->string($data, 'name'),
                $this->optionalString($data, 'agenda'),
                $this->strings($data, 'tags'),
                $this->strings($data, 'freeTags'),
                $this->int($data, 'weight'),
                ItemStatus::tryFrom($status) ?? throw $this->malformed('status', 'one of "open", "closed"'),
            );
        } catch (InvalidNpc $exception) {
            throw ValueNotConvertible::new(Npc::class, self::NAME, 'Stored campaign npcs: '.$exception->getMessage());
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

    private function optionalString(mixed $data, string $field): ?string
    {
        return null === $this->field($data, $field) ? null : $this->string($data, $field);
    }

    private function malformed(string $field, string $expected): ValueNotConvertible
    {
        return ValueNotConvertible::new($field, self::NAME, \sprintf('Stored campaign npcs: "%s" must be %s.', $field, $expected));
    }
}
