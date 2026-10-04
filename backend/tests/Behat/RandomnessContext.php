<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\Roll;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use PHPUnit\Framework\Assert;

final class RandomnessContext implements Context
{
    private const string NUMBER_LIST = '(?P<numbers>\d+(?:(?:, | and )\d+)*)';

    private ScriptedRandomNumberGenerator $random;
    private ?Roll $roll = null;
    private ?InvalidDiceExpression $rejection = null;

    public function __construct()
    {
        $this->random = new ScriptedRandomNumberGenerator();
    }

    #[Given('/^the dice will roll '.self::NUMBER_LIST.'$/')]
    public function theDiceWillRoll(string $numbers): void
    {
        $this->random = new ScriptedRandomNumberGenerator(...$this->parseNumbers($numbers));
    }

    #[When('I roll :notation')]
    public function iRoll(string $notation): void
    {
        try {
            $this->roll = DiceExpression::fromString($notation)->roll($this->random);
        } catch (InvalidDiceExpression $rejection) {
            $this->rejection = $rejection;
        }
    }

    #[Then('/^the dice show '.self::NUMBER_LIST.'$/')]
    public function theDiceShow(string $numbers): void
    {
        Assert::assertSame($this->parseNumbers($numbers), $this->roll()->dice());
    }

    #[Then('the total is :total')]
    public function theTotalIs(int $total): void
    {
        Assert::assertSame($total, $this->roll()->total());
    }

    #[Then('the dice expression is rejected')]
    public function theDiceExpressionIsRejected(): void
    {
        Assert::assertNull($this->roll, 'Expected the dice expression to be rejected, but it was rolled.');
        Assert::assertInstanceOf(InvalidDiceExpression::class, $this->rejection);
    }

    private function roll(): Roll
    {
        Assert::assertNull($this->rejection, $this->rejection?->getMessage() ?? '');
        Assert::assertNotNull($this->roll, 'No dice were rolled.');

        return $this->roll;
    }

    /**
     * @return list<int>
     */
    private function parseNumbers(string $numbers): array
    {
        return array_map(intval(...), preg_split('/, | and /', $numbers) ?: []);
    }
}
