<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Facts;

use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\PlaceId;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\GameSystem\FactSlotType;

/**
 * What a player fills a Fact slot with by hand: a text, an existing NPC, Thread or Place, or a new one
 * by name (the Campaign creates it, open, with weight 1 and no tags, under the id given here).
 * Whether the value fits the slot's type is checked by assertFits().
 */
final readonly class FactFill
{
    private function __construct(
        public ?string $text,
        public ?FactLink $subject,
        public ?string $newName,
    ) {
    }

    public static function text(string $text): self
    {
        return new self($text, null, null);
    }

    public static function existingNpc(NpcId $id): self
    {
        return new self(null, FactLink::npc($id), null);
    }

    public static function existingThread(ThreadId $id): self
    {
        return new self(null, FactLink::thread($id), null);
    }

    public static function existingPlace(PlaceId $id): self
    {
        return new self(null, FactLink::place($id), null);
    }

    public static function newNpc(NpcId $id, string $name): self
    {
        return new self(null, FactLink::npc($id), $name);
    }

    public static function newThread(ThreadId $id, string $name): self
    {
        return new self(null, FactLink::thread($id), $name);
    }

    public static function newPlace(PlaceId $id, string $name): self
    {
        return new self(null, FactLink::place($id), $name);
    }

    public function createsItem(): bool
    {
        return null !== $this->newName;
    }

    /**
     * @throws InvalidFact when the value is not the kind the slot takes
     */
    public function assertFits(FactSlotType $type): void
    {
        $fits = match ($type) {
            FactSlotType::Text => null !== $this->text,
            FactSlotType::Npc => FactLinkKind::Npc === $this->subject?->kind,
            FactSlotType::Thread => FactLinkKind::Thread === $this->subject?->kind,
            FactSlotType::Place => FactLinkKind::Place === $this->subject?->kind,
        };

        if (!$fits) {
            throw InvalidFact::because(\sprintf('A "%s" slot takes %s.', $type->value, match ($type) {
                FactSlotType::Text => 'a text', FactSlotType::Npc => 'an NPC', FactSlotType::Thread => 'a Thread', FactSlotType::Place => 'a Place',
            }));
        }
    }
}
