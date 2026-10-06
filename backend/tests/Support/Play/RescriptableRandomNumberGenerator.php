<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Randomness\Domain\RandomNumberGenerator;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;

/**
 * A scripted generator whose numbers can be scripted again after the container handed it out (a
 * test container cannot replace a service already used, e.g. between two requests).
 */
final class RescriptableRandomNumberGenerator implements RandomNumberGenerator
{
    private ScriptedRandomNumberGenerator $scripted;

    public function __construct()
    {
        $this->scripted = new ScriptedRandomNumberGenerator();
    }

    /**
     * Replaces the numbers still to come.
     */
    public function script(int ...$numbers): void
    {
        $this->scripted = new ScriptedRandomNumberGenerator(...$numbers);
    }

    public function between(int $min, int $max): int
    {
        return $this->scripted->between($min, $max);
    }
}
