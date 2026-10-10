<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

final class InvalidSceneTitle extends \DomainException
{
    public static function blank(): self
    {
        return new self('A scene title must not be blank.');
    }

    public static function missing(): self
    {
        return new self('A scene without a Scene Type needs a title.');
    }

    public static function tooLong(int $maxLength, int $length): self
    {
        return new self(\sprintf('A scene title must be at most %d characters, got %d.', $maxLength, $length));
    }
}
