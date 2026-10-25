<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * The roll of the current check step waiting for the player's Keep or Push (ADR 0041): a pool roll
 * of a check that declares a push, with a die that failed. The journal entry holds the roll; the
 * FlowRun keeps what completing the step needs: the Character who rolled, the Check difficulty
 * that applied (a push bands the new successes at it) and the band the roll landed in (Keep).
 */
final readonly class PendingCheck
{
    /**
     * @throws \InvalidArgumentException when a key or an id is blank
     */
    public function __construct(
        public string $stepKey,
        public string $entryId,
        public string $characterId,
        public ?int $difficulty,
        public string $bandKey,
    ) {
        foreach (['stepKey' => $stepKey, 'entryId' => $entryId, 'characterId' => $characterId, 'bandKey' => $bandKey] as $field => $value) {
            if ('' === trim($value)) {
                throw new \InvalidArgumentException(\sprintf('A pending check needs a %s.', $field));
            }
        }
    }
}
