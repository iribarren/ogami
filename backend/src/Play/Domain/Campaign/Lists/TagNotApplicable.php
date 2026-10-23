<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Lists;

use App\Play\Domain\GameSystem\Flow\ItemKind;

/**
 * An NPC, Thread or Character was given a Tag of the pinned release that is limited to other kinds.
 * The API answers it like an UnknownReleaseTag (422).
 */
final class TagNotApplicable extends \DomainException
{
    public static function forKind(string $key, ItemKind $kind): self
    {
        return new self(\sprintf('Tag "%s" does not apply to %s.', $key, match ($kind) {
            ItemKind::Npc => 'NPCs',
            ItemKind::Thread => 'Threads',
            ItemKind::Character => 'Characters',
        }));
    }
}
