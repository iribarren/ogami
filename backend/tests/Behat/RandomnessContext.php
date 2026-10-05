<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Randomness\Domain\DiceExpression;
use App\Randomness\Domain\DiceGroup;
use App\Randomness\Domain\InvalidDiceExpression;
use App\Randomness\Domain\Oracle\InvalidLikelihoodOracle;
use App\Randomness\Domain\Oracle\InvalidOracleTable;
use App\Randomness\Domain\Oracle\LikelihoodAnswer;
use App\Randomness\Domain\Oracle\LikelihoodOracle;
use App\Randomness\Domain\Oracle\OracleTableResult;
use App\Randomness\Domain\Oracle\OracleTableSet;
use App\Randomness\Domain\Oracle\OracleTableStep;
use App\Randomness\Domain\Roll;
use App\Randomness\Domain\RolledDie;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\TableNode;
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

    /** @var list<array<string, mixed>> */
    private array $oracleTables = [];
    private ?OracleTableResult $oracleTableResult = null;
    private ?InvalidOracleTable $oracleTableRejection = null;

    /** @var array<string, mixed> */
    private array $likelihoodOracle = [];
    private ?LikelihoodAnswer $likelihoodAnswer = null;
    private ?InvalidLikelihoodOracle $likelihoodRejection = null;

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

    #[Given('/^the oracle table "(?P<key>[^"]+)" rolls "(?P<dice>[^"]+)":$/')]
    public function theOracleTableRolls(string $key, string $dice, TableNode $entries): void
    {
        $this->oracleTables[] = ['key' => $key, 'name' => ucfirst($key), 'dice' => $dice, 'entries' => $this->oracleTableEntries($entries)];
    }

    #[Given('/^the weighted oracle table "(?P<key>[^"]+)":$/')]
    public function theWeightedOracleTable(string $key, TableNode $entries): void
    {
        $this->oracleTables[] = ['key' => $key, 'name' => ucfirst($key), 'entries' => $this->oracleTableEntries($entries)];
    }

    #[When('I consult the oracle table :key')]
    public function iConsultTheOracleTable(string $key): void
    {
        try {
            $this->oracleTableResult = OracleTableSet::fromArray($this->oracleTables)->resolve($key, $this->random);
        } catch (InvalidOracleTable $rejection) {
            $this->oracleTableRejection = $rejection;
        }
    }

    #[Then('the oracle table answers :text')]
    public function theOracleTableAnswers(string $text): void
    {
        $steps = $this->oracleTableResult()->steps();

        Assert::assertSame($text, $steps[\count($steps) - 1]->text());
    }

    #[Then('the oracle table steps are:')]
    public function theOracleTableStepsAre(TableNode $expected): void
    {
        Assert::assertSame($expected->getColumnsHash(), array_map(
            static fn (OracleTableStep $step): array => [
                'table' => $step->tableKey(),
                'dice' => $step->dice(),
                'total' => (string) $step->total(),
                'text' => $step->text(),
            ],
            $this->oracleTableResult()->steps(),
        ));
    }

    #[Then('/^the oracle table is rejected because "(?P<reason>.+)"$/')]
    public function theOracleTableIsRejectedBecause(string $reason): void
    {
        Assert::assertNull($this->oracleTableResult, 'Expected the oracle table to be rejected, but it was consulted.');
        Assert::assertInstanceOf(InvalidOracleTable::class, $this->oracleTableRejection);
        Assert::assertStringContainsString($reason, $this->oracleTableRejection->getMessage());
    }

    #[Given('/^a likelihood oracle rolls 1d(?P<sides>\d+) with (?P<percent>\d+)% exceptional results and the levels:$/')]
    public function aLikelihoodOracleRolls(int $sides, int $percent, TableNode $levels): void
    {
        $this->likelihoodOracle = [
            'sides' => $sides,
            'exceptionalPercent' => $percent,
            'levels' => array_map(
                static fn (array $row): array => ['key' => $row['key'], 'label' => $row['label'], 'target' => (int) $row['target']],
                $levels->getColumnsHash(),
            ),
        ];
    }

    #[Given('/^its chaos factor goes from (?P<min>\d+) to (?P<max>\d+), neutral at (?P<neutral>\d+), shifting the target (?P<shift>\d+) per point$/')]
    public function itsChaosFactorGoesFrom(int $min, int $max, int $neutral, int $shift): void
    {
        $this->likelihoodOracle['chaos'] = ['min' => $min, 'max' => $max, 'neutral' => $neutral, 'shiftPerPoint' => $shift];
    }

    #[When('/^I ask the likelihood oracle with the level "(?P<level>[^"]+)"(?: and the chaos factor (?P<chaosFactor>-?\d+))?$/')]
    public function iAskTheLikelihoodOracle(string $level, ?string $chaosFactor = null): void
    {
        try {
            $this->likelihoodAnswer = LikelihoodOracle::fromArray($this->likelihoodOracle)
                ->ask($level, null === $chaosFactor || '' === $chaosFactor ? null : (int) $chaosFactor, $this->random);
        } catch (InvalidLikelihoodOracle $rejection) {
            $this->likelihoodRejection = $rejection;
        }
    }

    #[Then('/^the likelihood oracle answers "(?P<answer>[a-z_]+)" with a roll of (?P<roll>\d+) against a target of (?P<target>\d+)$/')]
    public function theLikelihoodOracleAnswers(string $answer, int $roll, int $target): void
    {
        Assert::assertNull($this->likelihoodRejection, $this->likelihoodRejection?->getMessage() ?? '');
        Assert::assertNotNull($this->likelihoodAnswer, 'No likelihood oracle was asked.');
        Assert::assertSame(
            [$answer, $roll, $target],
            [$this->likelihoodAnswer->answer()->value, $this->likelihoodAnswer->roll(), $this->likelihoodAnswer->effectiveTarget()],
        );
    }

    #[Then('/^the likelihood oracle is rejected because "(?P<reason>.+)"$/')]
    public function theLikelihoodOracleIsRejectedBecause(string $reason): void
    {
        Assert::assertNull($this->likelihoodAnswer, 'Expected the likelihood oracle to be rejected, but it answered.');
        Assert::assertInstanceOf(InvalidLikelihoodOracle::class, $this->likelihoodRejection);
        Assert::assertStringContainsString($reason, $this->likelihoodRejection->getMessage());
    }

    private function oracleTableResult(): OracleTableResult
    {
        Assert::assertNull($this->oracleTableRejection, $this->oracleTableRejection?->getMessage() ?? '');
        Assert::assertNotNull($this->oracleTableResult, 'No oracle table was consulted.');

        return $this->oracleTableResult;
    }

    /**
     * Turns rows such as "| min | max | text | table |" into entry definitions; empty cells are left out.
     *
     * @return list<array<string, int|string>>
     */
    private function oracleTableEntries(TableNode $entries): array
    {
        return array_map(
            static function (array $row): array {
                $entry = [];
                foreach ($row as $field => $value) {
                    if ('' !== $value) {
                        $entry[$field] = \in_array($field, ['min', 'max', 'weight'], true) ? (int) $value : $value;
                    }
                }

                return $entry;
            },
            $entries->getColumnsHash(),
        );
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
