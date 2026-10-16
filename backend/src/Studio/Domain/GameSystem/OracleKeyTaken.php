<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * Another oracle table or likelihood oracle of the draft already uses this key: oracle keys are
 * one namespace.
 */
final class OracleKeyTaken extends \DomainException
{
    public static function withKey(string $key): self
    {
        return new self(\sprintf('The draft already has an oracle "%s".', $key));
    }
}
