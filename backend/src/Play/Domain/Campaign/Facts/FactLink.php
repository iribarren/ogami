<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Facts;

use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\PlaceId;
use App\Play\Domain\Campaign\Lists\ThreadId;

/**
 * A reference by id from a Campaign fact to an NPC, a Thread or another fact. Names are read when
 * shown, so a rename shows everywhere. Whether the target exists is the Campaign's check. A slot
 * fact's subject is a link too (an NPC or a Thread).
 */
final readonly class FactLink
{
    private function __construct(
        public FactLinkKind $kind,
        public string $id,
    ) {
    }

    /**
     * @throws InvalidFact when the id is blank
     */
    public static function of(FactLinkKind $kind, string $id): self
    {
        if ('' === trim($id)) {
            throw InvalidFact::because('A link must name its target.');
        }

        return new self($kind, $id);
    }

    public static function npc(NpcId $id): self
    {
        return new self(FactLinkKind::Npc, $id->toString());
    }

    public static function thread(ThreadId $id): self
    {
        return new self(FactLinkKind::Thread, $id->toString());
    }

    public static function place(PlaceId $id): self
    {
        return new self(FactLinkKind::Place, $id->toString());
    }

    public static function fact(FactId $id): self
    {
        return new self(FactLinkKind::Fact, $id->toString());
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->id === $other->id;
    }
}
