<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\GameSystem\Flow\CheckStep;

/**
 * The roll of the current check step waiting for the player's Keep or Push (ADR 0041): a pool roll
 * of a check that declares a push, with a die that failed. The journal entry holds the roll; the
 * FlowRun keeps what completing the step needs: the Character who rolled, the Check difficulty
 * that applied (a push bands the new successes at it) and the band the roll landed in (Keep). A roll
 * of a check set (ADR 0042) also keeps the check the player picked; a single-check step's roll keeps
 * none, as do rows stored before check sets, so they read the step's own check.
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
        public ?string $checkKey = null,
    ) {
        foreach (['stepKey' => $stepKey, 'entryId' => $entryId, 'characterId' => $characterId, 'bandKey' => $bandKey, 'checkKey' => $checkKey ?? '-'] as $field => $value) {
            if ('' === trim($value)) {
                throw new \InvalidArgumentException(\sprintf('A pending check needs a %s.', $field));
            }
        }
    }

    /**
     * The key of the check that rolled: the picked one, else the one a single-check step names.
     *
     * @throws \LogicException when the roll of a check set kept no check
     */
    public function checkKeyFor(CheckStep $step): string
    {
        return $this->checkKey ?? $step->singleCheck() ?? throw new \LogicException(\sprintf('The pending roll of check step "%s" names no check of its set.', $this->stepKey));
    }
}
