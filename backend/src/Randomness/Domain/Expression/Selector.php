<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Expression;

/**
 * How a dice term selects the dice that count: "k" is parsed as "kh".
 */
enum Selector: string
{
    case KeepHighest = 'kh';
    case KeepLowest = 'kl';
    case DropHighest = 'dh';
    case DropLowest = 'dl';

    public static function fromToken(string $token): self
    {
        return 'k' === $token ? self::KeepHighest : self::from($token);
    }

    public function keeps(): bool
    {
        return self::KeepHighest === $this || self::KeepLowest === $this;
    }

    /**
     * Flags, in roll order, which dice count towards the total.
     *
     * Ties are deterministic: among dice showing the same value, the
     * earlier-rolled die is kept and the later-rolled one dropped.
     *
     * @param list<int> $values the dice in roll order
     *
     * @return list<bool>
     */
    public function select(array $values, int $count): array
    {
        $keepCount = $this->keeps() ? $count : \count($values) - $count;
        $preferHighest = self::KeepHighest === $this || self::DropLowest === $this;

        $order = array_keys($values);
        usort($order, static fn (int $a, int $b): int => ($preferHighest
            ? $values[$b] <=> $values[$a]
            : $values[$a] <=> $values[$b]) ?: $a <=> $b);

        $kept = array_fill(0, \count($values), false);
        foreach (\array_slice($order, 0, $keepCount) as $index) {
            $kept[$index] = true;
        }

        return array_values($kept);
    }
}
