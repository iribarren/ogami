<?php

declare(strict_types=1);

namespace App\Randomness\Application;

use App\Randomness\Domain\DiceGroup;
use App\Randomness\Domain\RolledDie;

/**
 * The dice rolled for one dice term, such as "4d6kh3".
 */
final readonly class DiceGroupView
{
    /**
     * @param list<RolledDieView> $dice     every die rolled, in roll order, dropped ones included
     * @param int                 $subtotal the sum of the kept dice
     */
    public function __construct(
        public string $notation,
        public int $sides,
        public array $dice,
        public int $subtotal,
    ) {
    }

    public static function fromGroup(DiceGroup $group): self
    {
        return new self(
            $group->notation(),
            $group->sides(),
            array_map(
                static fn (RolledDie $die): RolledDieView => new RolledDieView($die->value(), $die->isKept()),
                $group->dice(),
            ),
            $group->subtotal(),
        );
    }
}
