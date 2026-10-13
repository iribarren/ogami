<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

/**
 * The difficulty given to a check is outside the min..max the check declares.
 */
final class CheckDifficultyOutOfRange extends \DomainException
{
    public static function of(string $checkKey, int $value, CheckDifficulty $difficulty): self
    {
        return new self(\sprintf(
            'Difficulty %d is outside %s of check "%s".',
            $value,
            match (true) {
                null !== $difficulty->min && null !== $difficulty->max => \sprintf('%d to %d', $difficulty->min, $difficulty->max),
                null !== $difficulty->min => \sprintf('the minimum %d', $difficulty->min),
                default => \sprintf('the maximum %d', $difficulty->max),
            },
            $checkKey,
        ));
    }
}
