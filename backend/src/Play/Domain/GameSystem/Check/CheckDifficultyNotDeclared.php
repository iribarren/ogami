<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

/**
 * A difficulty was given to a check that declares none.
 */
final class CheckDifficultyNotDeclared extends \DomainException
{
    public static function forCheck(string $checkKey): self
    {
        return new self(\sprintf('Check "%s" declares no difficulty.', $checkKey));
    }
}
