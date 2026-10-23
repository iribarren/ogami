<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\CheckDifficulty;
use App\Play\Domain\GameSystem\Check\ReferenceLabels;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Play\Domain\Journal\CheckContent;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\JournalEntryContents;
use App\Randomness\Domain\DiceExpression;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckContent::class)]
#[CoversClass(InvalidJournalEntryContent::class)]
final class CheckContentTest extends TestCase
{
    /**
     * Vi (hands 3) rolls the check "edge": 2d6 + {hands} against a difficulty of 8, with a situational
     * modifier of -1; the dice show 5 and 4.
     */
    public static function edgeCheck(): CheckContent
    {
        $check = new Check(
            'edge',
            'Edge',
            'Roll with your hands',
            DiceExpression::fromString('2d6'),
            Formula::fromString('{hands}'),
            new CheckDifficulty('Difficulty', 8),
            [new CheckBand('miss', 'Miss', Formula::fromString('{check:difficulty} - 1')), new CheckBand('hit', 'Hit')],
        );
        $template = new SheetTemplate([new NumberField('hands', 'Hands')]);
        $result = $check->perform(new ScriptedRandomNumberGenerator(5, 4), SheetValues::of($template, ['hands' => 3], []), null, -1);

        return CheckContent::fromResult('char-1', 'Vi', $check, $result, new ReferenceLabels($template));
    }

    #[Test]
    public function aCheckKeepsTheCharacterTheCheckTheDiceTheModifiersTheTotalAndTheBand(): void
    {
        $content = self::edgeCheck();

        self::assertSame('check', $content->kind());
        self::assertSame([
            'kind' => 'check',
            'characterId' => 'char-1',
            'characterName' => 'Vi',
            'checkKey' => 'edge',
            'checkLabel' => 'Edge',
            'expression' => '2d6',
            'groups' => [['notation' => '2d6', 'sides' => 6, 'dice' => [['value' => 5, 'kept' => true], ['value' => 4, 'kept' => true]], 'subtotal' => 9]],
            'diceTotal' => 9,
            'modifier' => 3,
            'situational' => -1,
            'difficulty' => 8,
            'total' => 11,
            'bandKey' => 'hit',
            'bandLabel' => 'Hit',
            'hint' => 'Roll with your hands',
            'modifierFormula' => '{hands}',
            'modifierTerms' => [['reference' => '{hands}', 'label' => 'Hands', 'value' => 3]],
        ], $content->toArray());
        self::assertSame(['char-1', 'Vi', 'edge', 'Edge', '2d6', 9, 3, -1, 8, 11, 'hit', 'Hit'], [
            $content->characterId(), $content->characterName(), $content->checkKey(), $content->checkLabel(), $content->expression(),
            $content->diceTotal(), $content->modifier(), $content->situational(), $content->difficulty(), $content->total(), $content->bandKey(), $content->bandLabel(),
        ]);
        self::assertSame($content->toArray()['groups'], $content->groups());
    }

    #[Test]
    public function aCheckWithoutADifficultyStoresNull(): void
    {
        $check = new Check('luck', 'Luck', null, DiceExpression::fromString('d%'), null, null, [new CheckBand('only', 'Only')]);
        $result = $check->perform(new ScriptedRandomNumberGenerator(42), SheetValues::of(new SheetTemplate([]), [], []), null, 0);

        $content = CheckContent::fromResult('char-1', 'Vi', $check, $result, new ReferenceLabels(new SheetTemplate([])));

        self::assertNull($content->difficulty());
        self::assertNull($content->toArray()['difficulty']);
        self::assertNull($content->hint());
        self::assertNull($content->modifierFormula());
        self::assertSame([], $content->modifierTerms());
        self::assertEquals($content, JournalEntryContents::fromArray($content->toArray()));
    }

    #[Test]
    public function aCheckRoundTripsThroughItsArray(): void
    {
        $content = self::edgeCheck();

        self::assertEquals($content, JournalEntryContents::fromArray($content->toArray()));
    }

    #[Test]
    public function aBreakdownLabelsEveryReferenceOnceInTheOrderTheFormulaNamesThem(): void
    {
        $check = new Check('grit', 'Grit', null, DiceExpression::fromString('1d6'), Formula::fromString('{hot} + {hp.max} - {tracker:chaos} + {hot}'), null, [new CheckBand('only', 'Only')]);
        $template = new SheetTemplate([new NumberField('hot', 'Hot'), new TrackField('hp', 'Health', max: 6)]);
        $sheet = SheetValues::of($template, ['hot' => 2, 'hp' => ['current' => 3, 'max' => 6]], ['chaos' => 4]);
        $labels = new ReferenceLabels($template, ['chaos' => 'Chaos factor']);

        $content = CheckContent::fromResult('char-1', 'Vi', $check, $check->perform(new ScriptedRandomNumberGenerator(1), $sheet, null, 0), $labels);

        self::assertSame('{hot} + {hp.max} - {tracker:chaos} + {hot}', $content->modifierFormula());
        self::assertSame([
            ['reference' => '{hot}', 'label' => 'Hot', 'value' => 2],
            ['reference' => '{hp.max}', 'label' => 'Health max', 'value' => 6],
            ['reference' => '{tracker:chaos}', 'label' => 'Chaos factor', 'value' => 4],
        ], $content->modifierTerms());
        self::assertSame(6, $content->modifier());
        self::assertEquals($content, JournalEntryContents::fromArray($content->toArray()));
    }

    #[Test]
    public function aTrackerTheReleaseDoesNotNameReadsAsItsKey(): void
    {
        $check = new Check('grit', 'Grit', null, DiceExpression::fromString('1d6'), Formula::fromString('{tracker:chaos}'), null, [new CheckBand('only', 'Only')]);
        $template = new SheetTemplate([]);
        $result = $check->perform(new ScriptedRandomNumberGenerator(1), SheetValues::of($template, [], ['chaos' => 4]), null, 0);

        $content = CheckContent::fromResult('char-1', 'Vi', $check, $result, new ReferenceLabels($template));

        self::assertSame([['reference' => '{tracker:chaos}', 'label' => 'chaos', 'value' => 4]], $content->modifierTerms());
    }

    /**
     * Entries stored before the breakdown existed carry none of the three keys.
     */
    #[Test]
    public function anEntryStoredWithoutABreakdownReadsWithoutOne(): void
    {
        $legacy = array_diff_key(self::edgeCheck()->toArray(), ['hint' => true, 'modifierFormula' => true, 'modifierTerms' => true]);

        $content = JournalEntryContents::fromArray($legacy);

        self::assertInstanceOf(CheckContent::class, $content);
        self::assertSame([null, null, []], [$content->hint(), $content->modifierFormula(), $content->modifierTerms()]);
        self::assertSame([null, null, []], [$content->toArray()['hint'], $content->toArray()['modifierFormula'], $content->toArray()['modifierTerms']]);
        self::assertSame(3, $content->modifier());
    }

    /**
     * @param array<mixed> $data
     */
    #[Test]
    #[DataProvider('malformedData')]
    public function malformedStoredDataIsRejected(array $data, string $message): void
    {
        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        JournalEntryContents::fromArray($data);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function malformedData(): iterable
    {
        $valid = self::edgeCheck()->toArray();

        foreach (['characterId', 'characterName', 'checkKey', 'checkLabel', 'expression', 'bandKey', 'bandLabel'] as $field) {
            yield \sprintf('%s missing', $field) => [array_diff_key($valid, [$field => true]), \sprintf('"%s" must be a string', $field)];
            yield \sprintf('%s not a string', $field) => [[$field => 7] + $valid, \sprintf('"%s" must be a string', $field)];
        }

        foreach (['diceTotal', 'modifier', 'situational', 'total'] as $field) {
            yield \sprintf('%s missing', $field) => [array_diff_key($valid, [$field => true]), \sprintf('"%s" must be an integer', $field)];
            yield \sprintf('%s not an integer', $field) => [[$field => '7'] + $valid, \sprintf('"%s" must be an integer', $field)];
        }

        yield 'difficulty missing' => [array_diff_key($valid, ['difficulty' => true]), '"difficulty" must be an integer or null'];
        yield 'difficulty not an integer' => [['difficulty' => 8.5] + $valid, '"difficulty" must be an integer'];
        yield 'groups missing' => [array_diff_key($valid, ['groups' => true]), '"groups" must be a list'];
        yield 'groups not a list' => [['groups' => 'none'] + $valid, '"groups" must be a list'];
        yield 'die kept not a bool' => [['groups' => [['notation' => '2d6', 'sides' => 6, 'dice' => [['value' => 3, 'kept' => 1]], 'subtotal' => 3]]] + $valid, '"kept" must be a boolean'];
        yield 'hint not a string' => [['hint' => 7] + $valid, '"hint" must be a string'];
        yield 'formula not a string' => [['modifierFormula' => 7] + $valid, '"modifierFormula" must be a string'];
        yield 'blank formula' => [['modifierFormula' => ' ', 'modifierTerms' => []] + $valid, 'A modifier formula must not be blank.'];
        yield 'terms not a list' => [['modifierTerms' => 'none'] + $valid, '"modifierTerms" must be a list'];
        yield 'terms not objects' => [['modifierTerms' => ['hands']] + $valid, '"modifierTerms" must be a list of objects'];
        yield 'term without a reference' => [['modifierTerms' => [['label' => 'Hands', 'value' => 3]]] + $valid, '"reference" must be a string'];
        yield 'term without a label' => [['modifierTerms' => [['reference' => '{hands}', 'value' => 3]]] + $valid, '"label" must be a string'];
        yield 'term value not an integer' => [['modifierTerms' => [['reference' => '{hands}', 'label' => 'Hands', 'value' => '3']]] + $valid, '"value" must be an integer'];
        yield 'terms without a formula' => [['modifierFormula' => null] + $valid, '"modifierTerms" must be empty when the check has no modifier formula'];
        yield 'blank character id' => [['characterId' => ' '] + $valid, 'A Character id must not be blank.'];
        yield 'blank check key' => [['checkKey' => ''] + $valid, 'A check key must not be blank.'];
        yield 'blank band key' => [['bandKey' => ''] + $valid, 'A band key must not be blank.'];
        yield 'total that is not the dice plus the modifiers' => [['total' => 12] + $valid, '"total" must be the dice total plus the modifier and the situational modifier'];
        yield 'total that overflows' => [['diceTotal' => \PHP_INT_MAX, 'modifier' => 1, 'total' => \PHP_INT_MAX] + $valid, '"total" must be the dice total plus the modifier and the situational modifier'];
    }
}
