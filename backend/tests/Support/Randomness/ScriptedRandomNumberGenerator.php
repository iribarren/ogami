<?php

declare(strict_types=1);

namespace App\Tests\Support\Randomness;

use App\Randomness\Domain\RandomNumberGenerator;

/**
 * Test double that returns predetermined numbers, in order, so rolls are deterministic.
 */
final class ScriptedRandomNumberGenerator implements RandomNumberGenerator
{
    /** @var list<int> */
    private array $numbers;

    public function __construct(int ...$numbers)
    {
        $this->numbers = array_values($numbers);
    }

    public function between(int $min, int $max): int
    {
        $number = array_shift($this->numbers);
        if (null === $number) {
            throw new \LogicException('No scripted number left.');
        }

        if ($number < $min || $number > $max) {
            throw new \LogicException(\sprintf('Scripted number %d is outside [%d, %d].', $number, $min, $max));
        }

        return $number;
    }
}
