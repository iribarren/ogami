<?php

declare(strict_types=1);

namespace App\Randomness\Domain\Oracle;

/**
 * A likelihood a question can be asked with (e.g. "unlikely"), and the roll that answers yes to it.
 */
final readonly class LikelihoodLevel
{
    public const int MAX_LABEL_LENGTH = 500;

    private string $label;

    /**
     * @throws InvalidLikelihoodOracle
     */
    public function __construct(
        private string $key,
        string $label,
        private int $target,
        int $sides,
    ) {
        if (!OracleTable::isValidKey($key)) {
            throw InvalidLikelihoodOracle::invalidLevelKey($key, OracleTable::MAX_KEY_LENGTH);
        }

        $this->label = trim($label);
        $labelLength = mb_strlen($this->label);
        if (0 === $labelLength || $labelLength > self::MAX_LABEL_LENGTH) {
            throw InvalidLikelihoodOracle::labelLength($key, $labelLength, self::MAX_LABEL_LENGTH);
        }

        if ($target < 0 || $target > $sides) {
            throw InvalidLikelihoodOracle::targetOutOfRange($key, $target, $sides);
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * The highest roll that answers yes at the neutral chaos factor.
     */
    public function target(): int
    {
        return $this->target;
    }
}
