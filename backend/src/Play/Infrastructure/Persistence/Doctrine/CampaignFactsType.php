<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Persistence\Doctrine;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Facts\Fact;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Facts\FactLink;
use App\Play\Domain\Campaign\Facts\FactLinkKind;
use App\Play\Domain\Campaign\Facts\InvalidFact;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;
use Doctrine\DBAL\Types\JsonType;

/**
 * Maps a campaign's Campaign facts to one JSON column (JSONB with the "jsonb" option). They are
 * immutable values identified by their id within the campaign, always loaded and saved with it
 * (ADR 0007).
 *
 * Stored shape: [{id, slot, label, text, subject, links}], in creation order; `slot` is a Fact slot
 * key or null (a free fact), `subject` is null or {kind, id} (an NPC, a Thread or a Place), `links` is a list
 * of {kind, id} with kind "npc", "thread", "place" or "fact". The column defaults to an empty list, which is
 * what a campaign stored before facts reads as. Reading checks the shape, the 500 limit, distinct
 * ids, one fact per slot and the rules Fact::of() holds (not whether the slot is in the release, nor
 * whether links and subjects name things of the campaign: the pinned release and the Campaign tell,
 * Campaign::assertFactsConsistent() after load); stored data that breaks any of them fails closed
 * with a clear error instead of loading a campaign in a state the domain refuses.
 */
final class CampaignFactsType extends JsonType
{
    public const string NAME = 'play_campaign_facts';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_array($value)) {
            throw InvalidType::new($value, self::NAME, ['array', 'null']);
        }

        $items = [];
        foreach ($value as $fact) {
            if (!$fact instanceof Fact) {
                throw InvalidType::new($fact, self::NAME, [Fact::class]);
            }

            $items[] = [
                'id' => $fact->id()->toString(),
                'slot' => $fact->slot(),
                'label' => $fact->label(),
                'text' => $fact->text(),
                'subject' => $fact->subject() instanceof FactLink ? $this->link($fact->subject()) : null,
                'links' => array_map($this->link(...), $fact->links()),
            ];
        }

        return parent::convertToDatabaseValue($items, $platform);
    }

    /**
     * @return list<Fact>|null
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?array
    {
        $data = parent::convertToPHPValue($value, $platform);
        if (null === $data) {
            return null;
        }

        if (!\is_array($data) || !array_is_list($data)) {
            throw $this->malformed('facts', 'a list');
        }

        if (\count($data) > Campaign::MAX_FACTS) {
            throw ValueNotConvertible::new('facts', self::NAME, \sprintf('Stored campaign facts: at most %d facts are allowed, got %d.', Campaign::MAX_FACTS, \count($data)));
        }

        $items = array_map($this->fact(...), $data);
        $ids = [];
        $slots = [];
        foreach ($items as $item) {
            $id = $item->id()->toString();
            if (isset($ids[$id])) {
                throw ValueNotConvertible::new('facts', self::NAME, \sprintf('Stored campaign facts: id "%s" is listed twice.', $id));
            }

            $ids[$id] = true;
            if (null !== $item->slot()) {
                if (isset($slots[$item->slot()])) {
                    throw ValueNotConvertible::new('facts', self::NAME, \sprintf('Stored campaign facts: slot "%s" holds two facts.', $item->slot()));
                }

                $slots[$item->slot()] = true;
            }
        }

        return $items;
    }

    /**
     * @return array{kind: string, id: string}
     */
    private function link(FactLink $link): array
    {
        return ['kind' => $link->kind->value, 'id' => $link->id];
    }

    private function fact(mixed $data): Fact
    {
        $subject = $this->field($data, 'subject');

        try {
            return Fact::of(
                FactId::fromString($this->string($data, 'id')),
                $this->optionalString($data, 'slot'),
                $this->optionalString($data, 'label'),
                $this->optionalString($data, 'text'),
                null === $subject ? null : $this->linkFrom($subject, 'subject'),
                $this->links($data),
            );
        } catch (InvalidFact $exception) {
            throw ValueNotConvertible::new(Fact::class, self::NAME, 'Stored campaign facts: '.$exception->getMessage());
        }
    }

    /**
     * @return list<FactLink>
     */
    private function links(mixed $data): array
    {
        $value = $this->field($data, 'links');
        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->malformed('links', 'a list of links');
        }

        return array_map(fn (mixed $link): FactLink => $this->linkFrom($link, 'links'), $value);
    }

    private function linkFrom(mixed $data, string $field): FactLink
    {
        $kind = $this->field($data, 'kind');
        if (!\is_string($kind) || !($linkKind = FactLinkKind::tryFrom($kind)) instanceof FactLinkKind) {
            throw $this->malformed($field, 'a link with kind "npc", "thread", "place" or "fact"');
        }

        return FactLink::of($linkKind, $this->string($data, 'id'));
    }

    private function field(mixed $data, string $field): mixed
    {
        return \is_array($data) ? ($data[$field] ?? null) : null;
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
        return ValueNotConvertible::new($field, self::NAME, \sprintf('Stored campaign facts: "%s" must be %s.', $field, $expected));
    }
}
