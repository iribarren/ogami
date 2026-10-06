<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\LikelihoodContent;
use App\Randomness\Domain\Oracle\LikelihoodAnswer;
use App\Randomness\Domain\Oracle\LikelihoodOracle;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LikelihoodContent::class)]
#[CoversClass(InvalidJournalEntryContent::class)]
final class LikelihoodContentTest extends TestCase
{
    #[Test]
    public function aLikelihoodAnswerKeepsTheOracleTheQuestionAndTheAnswer(): void
    {
        $content = LikelihoodContent::fromAnswer('fate', 'Fate', '  Is the gate open? ', self::likelyAnswer());

        self::assertSame('likelihood', $content->kind());
        self::assertSame('fate', $content->oracleKey());
        self::assertSame('Fate', $content->oracleName());
        self::assertSame('Is the gate open?', $content->question());
        self::assertSame([
            'kind' => 'likelihood',
            'oracleKey' => 'fate',
            'oracleName' => 'Fate',
            'question' => 'Is the gate open?',
            'answer' => 'yes',
            'roll' => 60,
            'sides' => 100,
            'effectiveTarget' => 75,
            'likelihood' => 'likely',
            'likelihoodLabel' => 'Likely',
            'chaosFactor' => 7,
        ], $content->toArray());
    }

    #[Test]
    public function anOracleWithoutChaosHasNoChaosFactor(): void
    {
        $answer = LikelihoodOracle::fromArray(self::definition(chaos: false))->ask('likely', null, new ScriptedRandomNumberGenerator(90));

        $content = LikelihoodContent::fromAnswer('fate', 'Fate', null, $answer);

        self::assertNull($content->toArray()['chaosFactor']);
        self::assertSame('no', $content->toArray()['answer']);
        self::assertSame(65, $content->toArray()['effectiveTarget']);
    }

    #[Test]
    #[DataProvider('noQuestions')]
    public function aMissingOrBlankQuestionIsNull(?string $question): void
    {
        self::assertNull(LikelihoodContent::fromAnswer('fate', 'Fate', $question, self::likelyAnswer())->question());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function noQuestions(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => [" \t\n "];
    }

    #[Test]
    public function aQuestionOfFiveHundredCharactersIsAccepted(): void
    {
        $question = str_repeat('é', 500);

        self::assertSame($question, LikelihoodContent::fromAnswer('fate', 'Fate', $question, self::likelyAnswer())->question());
    }

    #[Test]
    public function aQuestionAboveFiveHundredCharactersIsRejected(): void
    {
        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains('A question must be at most 500 characters, got 501.');

        LikelihoodContent::fromAnswer('fate', 'Fate', str_repeat('a', 501), self::likelyAnswer());
    }

    /**
     * "Likely" (65) at chaos 7 (+10): a roll of 60 is a plain yes against 75.
     */
    public static function likelyAnswer(): LikelihoodAnswer
    {
        return LikelihoodOracle::fromArray(self::definition())->ask('likely', 7, new ScriptedRandomNumberGenerator(60));
    }

    /**
     * @return array<string, mixed>
     */
    private static function definition(bool $chaos = true): array
    {
        $definition = [
            'sides' => 100,
            'levels' => [
                ['key' => 'unlikely', 'label' => 'Unlikely', 'target' => 35],
                ['key' => 'likely', 'label' => 'Likely', 'target' => 65],
            ],
            'exceptionalPercent' => 20,
        ];

        if ($chaos) {
            $definition['chaos'] = ['min' => 1, 'max' => 9, 'neutral' => 5, 'shiftPerPoint' => 5];
        }

        return $definition;
    }
}
