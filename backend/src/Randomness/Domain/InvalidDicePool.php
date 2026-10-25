<?php

declare(strict_types=1);

namespace App\Randomness\Domain;

final class InvalidDicePool extends \DomainException
{
    public static function count(int $count, int $min, int $max): self
    {
        return new self(\sprintf('A dice pool rolls %d to %d dice, %d given.', $min, $max, $count));
    }

    public static function sides(int $sides, int $min, int $max): self
    {
        return new self(\sprintf('The dice of a pool have %d to %d sides, %d given.', $min, $max, $sides));
    }

    public static function successAt(int $successAt, int $sides): self
    {
        return new self(\sprintf('A die of a pool succeeds from a face between 1 and its %d sides, %d given.', $sides, $successAt));
    }
}
