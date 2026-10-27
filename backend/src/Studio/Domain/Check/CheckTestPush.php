<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

/**
 * The push a saved pool check declares (ADR 0041), as far as a test roll shows it: its label and the
 * cap on the dice it re-rolls (ADR 0044). The cost is never applied in a test roll, so its effects
 * are not read.
 */
final readonly class CheckTestPush
{
    public const int MAX_LABEL = 100;
    public const int MAX_EFFECTS = 10;
    public const int MAX_DICE = 100;

    /**
     * @param ?int $maxDice the most failed regular dice a push re-rolls, 1..100; null re-rolls every one
     *
     * @throws \InvalidArgumentException when the label is blank or longer than the contract allows, or the cap is outside 1..100
     */
    public function __construct(
        public string $label,
        public ?int $maxDice = null,
    ) {
        if (null !== $maxDice && ($maxDice < 1 || $maxDice > self::MAX_DICE)) {
            throw new \InvalidArgumentException(\sprintf('A push cap is 1 to %d dice, %d given.', self::MAX_DICE, $maxDice));
        }

        if ('' === trim($label) || mb_strlen($label) > self::MAX_LABEL) {
            throw new \InvalidArgumentException(\sprintf('A push label is 1 to %d characters.', self::MAX_LABEL));
        }
    }
}
