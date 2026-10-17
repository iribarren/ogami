<?php

declare(strict_types=1);

namespace App\Studio\Domain\Check;

/**
 * A value sent for a test roll is not one the check or the sheet accepts. The message starts with
 * the path of the offending value in the request, e.g. "sheet.hands: must be 0 to 10, got 11".
 */
final class InvalidTestRoll extends \DomainException
{
    public static function at(string $path, string $reason): self
    {
        return new self(\sprintf('%s: %s', $path, $reason));
    }

    public static function notAnInteger(string $path): self
    {
        return self::at($path, 'must be an integer');
    }

    public static function outside(string $path, int $min, int $max, int $given): self
    {
        return self::at($path, \sprintf('must be %d to %d, got %d', $min, $max, $given));
    }
}
