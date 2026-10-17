<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\Command;

/**
 * Replaces the layout of the sheet of the owner's GameSystem draft: its columns and its declared
 * groups, renaming, reordering, adding and dropping groups. $expectedVersion is the draft version
 * the client read.
 */
final readonly class SaveSheetLayout implements Command
{
    /**
     * @param mixed        $columns the sheet's columns; null leaves one. Not typed: a malformed value is refused with its path
     * @param array<mixed> $groups  the groups as in a release document, each with the optional "previousName" of the group it renames
     */
    public function __construct(
        public string $ownerId,
        public string $key,
        public int $expectedVersion,
        public mixed $columns,
        public array $groups,
    ) {
    }
}
