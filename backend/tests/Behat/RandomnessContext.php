<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\DiceGroup;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\Roll;
use App\Randomness\Domain\RolledDie;
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

    #[Then('/^the "(?P<notation>[^"]+)" dice show '.self::NUMBER_LIST.'$/')]
    public function theDiceShow(string $notation, string $numbers): void
    {
        $group = $this->group($notation);

        Assert::assertSame($this->parseNumbers($numbers), array_map(static fn (RolledDie $die): int => $die->value(), $group->dice()));
    }

    #[Then('/^the (?:die|dice) showing '.self::NUMBER_LIST.' (?:is|are) dropped$/')]
    public function theDiceShowingAreDropped(string $numbers): void
    {
        Assert::assertSame($this->parseNumbers($numbers), $this->droppedDice());
    }

    #[Then('no dice are dropped')]
    public function noDiceAreDropped(): void
    {
        Assert::assertSame([], $this->droppedDice());
    }

    #[Then('the total is :total')]
    public function theTotalIs(int $total): void
    {
        Assert::assertSame($total, $this->roll()->total());
    }

    #[Then('the dice expression is rejected because :reason')]
    public function theDiceExpressionIsRejectedBecause(string $reason): void
    {
        Assert::assertNull($this->roll, 'Expected the dice expression to be rejected, but it was rolled.');
        Assert::assertInstanceOf(InvalidDiceExpression::class, $this->rejection);
        Assert::assertStringContainsString($reason, $this->rejection->getMessage());
    }

    private function roll(): Roll
    {
        Assert::assertNull($this->rejection, $this->rejection?->getMessage() ?? '');
        Assert::assertNotNull($this->roll, 'No dice were rolled.');

        return $this->roll;
    }

    private function group(string $notation): DiceGroup
    {
        foreach ($this->roll()->groups() as $group) {
            if ($notation === $group->notation()) {
                return $group;
            }
        }

        Assert::fail(\sprintf('No "%s" dice were rolled.', $notation));
    }

    /**
     * @return list<int>
     */
    private function droppedDice(): array
    {
        $dropped = [];
        foreach ($this->roll()->groups() as $group) {
            foreach ($group->dice() as $die) {
                if (!$die->isKept()) {
                    $dropped[] = $die->value();
                }
            }
        }

        return $dropped;
    }

    /**
     * @return list<int>
     */
    private function parseNumbers(string $numbers): array
    {
        return array_map(intval(...), preg_split('/, | and /', $numbers) ?: []);
    }
}
