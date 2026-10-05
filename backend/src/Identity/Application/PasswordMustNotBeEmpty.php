<?php

declare(strict_types=1);

namespace App\Identity\Application;

final class PasswordMustNotBeEmpty extends \InvalidArgumentException
{
    public static function create(): self
    {
        return new self('The password must not be empty.');
    }
}
