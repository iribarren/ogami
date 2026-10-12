<?php

declare(strict_types=1);

namespace App\Play\Application;

/**
 * An NPC in a Scene's cast, named as the campaign names it now.
 */
final readonly class CastMemberView
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
