<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * How a Phase picks its next scene: the listed Scene Types in order (sequence, may repeat), the
 * player's choice among them, or a roll on an oracle table whose entries name Scene Types.
 */
final readonly class SceneSelection
{
    /**
     * @param list<string> $sceneTypes Scene Type keys; empty for the oracle rule
     */
    private function __construct(
        public SelectionRule $rule,
        public array $sceneTypes,
        public ?string $table,
    ) {
    }

    /**
     * @param list<string> $sceneTypes
     */
    public static function sequence(array $sceneTypes): self
    {
        return new self(SelectionRule::Sequence, $sceneTypes, null);
    }

    /**
     * @param list<string> $sceneTypes
     */
    public static function player(array $sceneTypes): self
    {
        return new self(SelectionRule::Player, $sceneTypes, null);
    }

    public static function oracle(string $table): self
    {
        return new self(SelectionRule::Oracle, [], $table);
    }

    /**
     * The Scene Type a pick takes without asking: the only one a sequence or player selection
     * lists; null when there is a choice or a roll to make.
     */
    public function autoPick(): ?string
    {
        return 1 === \count($this->sceneTypes) ? $this->sceneTypes[0] : null;
    }
}
