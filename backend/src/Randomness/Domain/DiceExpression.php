<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

/**
 * A parsed dice notation such as "2d6+1" or "d20": roll N dice with M sides
 * and add the modifier K. Only NdM±K is supported for now.
 */
final readonly class DiceExpression
{
    public const int MAX_DICE = 100;
    public const int MIN_SIDES = 2;
    public const int MAX_SIDES = 1000;

    private const string PATTERN = '/^(?<count>\d+)?d(?<sides>\d+)(?:(?<sign>[+-])(?<modifier>\d+))?$/';

    private function __construct(
        private int $count,
        private int $sides,
        private int $modifier,
    ) {
        if ($count < 1 || $count > self::MAX_DICE) {
            throw InvalidDiceExpression::diceCountOutOfRange($count, self::MAX_DICE);
        }

        if ($sides < self::MIN_SIDES || $sides > self::MAX_SIDES) {
            throw InvalidDiceExpression::sidesOutOfRange($sides, self::MIN_SIDES, self::MAX_SIDES);
        }
    }

    /**
     * @throws InvalidDiceExpression
     */
    public static function fromString(string $notation): self
    {
        $normalized = strtolower(preg_replace('/\s+/', '', $notation) ?? '');

        if (1 !== preg_match(self::PATTERN, $normalized, $matches)) {
            throw InvalidDiceExpression::unparsable($notation);
        }

        $modifier = (int) ($matches['modifier'] ?? 0);

        return new self(
            '' === $matches['count'] ? 1 : (int) $matches['count'],
            (int) $matches['sides'],
            '-' === ($matches['sign'] ?? '+') ? -$modifier : $modifier,
        );
    }

    public function count(): int
    {
        return $this->count;
    }

    public function sides(): int
    {
        return $this->sides;
    }

    public function modifier(): int
    {
        return $this->modifier;
    }

    /**
     * The canonical notation, e.g. "1d20" for "d20" or "2d6+1" for " 2D6 + 1 ".
     */
    public function toString(): string
    {
        return match (true) {
            $this->modifier > 0 => \sprintf('%dd%d+%d', $this->count, $this->sides, $this->modifier),
            $this->modifier < 0 => \sprintf('%dd%d-%d', $this->count, $this->sides, -$this->modifier),
            default => \sprintf('%dd%d', $this->count, $this->sides),
        };
    }

    public function roll(RandomNumberGenerator $random): Roll
    {
        $dice = [];
        for ($i = 0; $i < $this->count; ++$i) {
            $dice[] = $random->between(1, $this->sides);
        }

        return new Roll($this, $dice);
    }
}
