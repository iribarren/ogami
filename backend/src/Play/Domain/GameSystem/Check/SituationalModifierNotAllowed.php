<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

/**
 * A situational modifier other than 0 was given to a check that takes none (`noSituationalModifier`).
 */
final class SituationalModifierNotAllowed extends \DomainException
{
    public static function forCheck(string $checkKey): self
    {
        return new self(\sprintf('Check "%s" takes no situational modifier.', $checkKey));
    }
}
