<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

use App\Play\Domain\GameSystem\Flow\SheetEffect;
use App\Play\Domain\GameSystem\Flow\SheetOperation;
use App\Play\Domain\GameSystem\Flow\TrackerEffect;

/**
 * What a pool check lets the player do after a roll (ADR 0041): push it, re-rolling the dice that
 * failed once, for a cost the release declares. The cost is a label the player reads before pushing
 * and effects on the rolling Character's sheet and on the Trackers, applied in order and all or nothing.
 *
 * ADR 0044: a push never re-rolls Hunger dice, and `maxDice` caps it at that many failed regular dice.
 */
final readonly class CheckPush
{
    public const int MAX_LABEL_LENGTH = 100;
    public const int MAX_EFFECTS = 10;
    public const int MAX_DICE = 100;

    /**
     * @param list<SheetEffect|TrackerEffect> $effects the cost, in apply order
     * @param ?int                            $maxDice the most failed regular dice a push re-rolls, 1..100; null for all of them
     *
     * @throws \InvalidArgumentException when the label is blank or too long, there are more than 10 effects, or the cap is out of 1..100
     */
    public function __construct(
        public string $label,
        public array $effects = [],
        public ?int $maxDice = null,
    ) {
        if (null !== $maxDice && ($maxDice < 1 || $maxDice > self::MAX_DICE)) {
            throw new \InvalidArgumentException(\sprintf('The cap of a push is 1 to %d dice, %d given.', self::MAX_DICE, $maxDice));
        }

        if ('' === trim($label) || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('The label of a push is 1 to %d characters.', self::MAX_LABEL_LENGTH));
        }

        if (\count($effects) > self::MAX_EFFECTS) {
            throw new \InvalidArgumentException(\sprintf('The cost of a push has at most %d effects.', self::MAX_EFFECTS));
        }
    }

    /**
     * The fields the player picks an option of: the field of each mark without an option, in cost order
     * (the same field twice when two marks pick from it). It tells a client which choices to offer.
     *
     * @return list<string>
     */
    public function picks(): array
    {
        $picks = [];
        foreach ($this->effects as $effect) {
            if ($effect instanceof SheetEffect && SheetOperation::Mark === $effect->op && null === $effect->value) {
                $picks[] = $effect->field;
            }
        }

        return $picks;
    }
}
