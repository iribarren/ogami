<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

/**
 * The situational modifier of a check is outside -100..100.
 */
final class SituationalModifierOutOfRange extends \DomainException
{
    public static function of(int $value): self
    {
        return new self(\sprintf('The situational modifier must be between %d and %d, %d given.', Check::MIN_SITUATIONAL, Check::MAX_SITUATIONAL, $value));
    }
}
