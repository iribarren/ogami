<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * The result of rolling a dice pool: every die in roll order and how many succeeded. The dice are
 * a list a later push can re-roll the failed ones of.
 */
final readonly class PoolRoll
{
    private int $successes;

    /**
     * @param list<PoolDie> $dice in roll order
     */
    public function __construct(
        private DicePool $pool,
        private array $dice,
    ) {
        $this->successes = \count(array_filter($dice, static fn (PoolDie $die): bool => $die->isSuccess()));
    }

    public function pool(): DicePool
    {
        return $this->pool;
    }

    /**
     * @return list<PoolDie>
     */
    public function dice(): array
    {
        return $this->dice;
    }

    public function size(): int
    {
        return \count($this->dice);
    }

    public function successes(): int
    {
        return $this->successes;
    }
}
