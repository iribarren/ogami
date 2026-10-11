<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Creates an open NPC with these Tags; name and agenda may hold placeholders. A missing name gets
 * a default when the Effect is applied.
 */
final readonly class CreateNpcEffect implements Effect
{
    /**
     * @param list<string> $tags Tag keys of the release
     */
    public function __construct(
        public ?string $name = null,
        public ?string $agenda = null,
        public array $tags = [],
    ) {
    }
}
