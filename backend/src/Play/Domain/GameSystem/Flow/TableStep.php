<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * Rolls an oracle table and branches on the rolled entry's key. "table" is the default; a step with
 * "tableBySceneType" rolls the table mapped to the current Scene Type instead, and the default in a
 * hook, outside a scene and for a Scene Type the map leaves out (ADR 0042).
 */
final readonly class TableStep extends Step
{
    /**
     * @param list<Effect>          $effects
     * @param list<TableBranch>     $branches
     * @param array<string, string> $tableBySceneType the table key of each Scene Type that has its own, by Scene Type key
     */
    public function __construct(
        string $key, string $title, ?string $prompt, ?string $tip, bool $mandatory, ?string $next, array $effects,
        public string $table,
        public array $branches,
        public ?Outcome $otherwise,
        public array $tableBySceneType = [],
    ) {
        parent::__construct($key, $title, $prompt, $tip, $mandatory, $next, $effects);
    }

    /**
     * The table the step rolls in a Scene of this Scene Type (null: in a hook or outside any scene).
     * The one place that decides it, for the FlowRun and for the command that rolls.
     */
    public function tableFor(?string $sceneType): string
    {
        return null === $sceneType ? $this->table : $this->tableBySceneType[$sceneType] ?? $this->table;
    }

    /**
     * Every table the step may roll: the default, then those of the map not already listed.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        return array_values(array_unique([$this->table, ...array_values($this->tableBySceneType)]));
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
