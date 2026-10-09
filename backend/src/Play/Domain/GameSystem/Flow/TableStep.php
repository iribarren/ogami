<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Rolls an oracle table and branches on the rolled entry's key.
 */
final readonly class TableStep extends Step
{
    /**
     * @param list<Effect>      $effects
     * @param list<TableBranch> $branches
     */
    public function __construct(
        string $key, string $title, ?string $prompt, ?string $tip, bool $mandatory, ?string $next, array $effects,
        public string $table,
        public array $branches,
        public ?Outcome $otherwise,
    ) {
        parent::__construct($key, $title, $prompt, $tip, $mandatory, $next, $effects);
    }

    /**
     * The branch of this entry key, else "otherwise" (null: neither).
     */
    public function outcomeFor(?string $entry): ?Outcome
    {
        foreach ($this->branches as $branch) {
            if ($branch->entry === $entry) {
                return $branch->outcome;
            }
        }

        return $this->otherwise;
    }
}
