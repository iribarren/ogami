<?php

declare(strict_types=1);

namespace App\Studio\Application;

/**
 * What the other sections of a draft offer the narrative editors as pickers: the keys a step,
 * a branch or an effect may name. Malformed content of the draft is left out.
 */
final readonly class NarrativeCatalogView
{
    /**
     * @param list<array{key: string, name: string, entries: list<string>}>                                                                    $tables     oracle tables with the keys of their entries
     * @param list<array{key: string, name: string, levels: list<string>}>                                                                     $likelihood likelihood oracles with the keys of their levels
     * @param list<array{key: string, label: string, bands: list<string>, difficulty: array{default: int, min: int|null, max: int|null}|null}> $checks     checks with their band keys and the difficulty the player sets
     * @param list<array{key: string, label: string}>                                                                                          $fields     the sheet's number and track fields
     */
    public function __construct(
        public array $tables,
        public array $likelihood,
        public array $checks,
        public array $fields,
    ) {
    }
}
