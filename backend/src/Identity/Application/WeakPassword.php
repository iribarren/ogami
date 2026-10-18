<?php

declare(strict_types=1);

namespace App\Identity\Application;

final class WeakPassword extends \InvalidArgumentException
{
    public static function outsideLength(int $min, int $max): self
    {
        return new self(\sprintf('The password must have between %d and %d characters.', $min, $max));
    }
}
