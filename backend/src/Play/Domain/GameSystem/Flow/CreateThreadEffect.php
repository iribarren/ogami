<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Creates an open Thread with these Tags; the name may hold placeholders. A missing name gets a
 * default when the Effect is applied.
 */
final readonly class CreateThreadEffect implements Effect
{
    /**
     * @param list<string> $tags Tag keys of the release
     */
    public function __construct(
        public ?string $name = null,
        public array $tags = [],
    ) {
    }
}
