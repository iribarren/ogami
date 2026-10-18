<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * What importing items from a pack would do to a draft: the items it would copy (the requested
 * ones and what they need), the needed items the draft already has and keeps (overrides), and the
 * requested items the draft already has (conflicts, which refuse the import). When nothing
 * conflicts, a dry run of the item rules may have refused the result: its path and message.
 */
final readonly class LibraryImportPlan
{
    /**
     * @param list<LibraryItemRef> $imports   in pack order
     * @param list<LibraryItemRef> $overrides in pack order
     * @param list<LibraryItemRef> $conflicts in the order requested
     */
    public function __construct(
        public array $imports,
        public array $overrides,
        public array $conflicts,
        public ?string $errorPath = null,
        public ?string $errorMessage = null,
    ) {
    }

    public function withError(string $path, string $message): self
    {
        return new self($this->imports, $this->overrides, $this->conflicts, $path, $message);
    }
}
