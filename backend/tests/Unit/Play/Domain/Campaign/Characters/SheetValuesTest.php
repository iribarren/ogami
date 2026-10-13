<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\Characters;

use App\Formula\Domain\Formula;
use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\Reference;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TextField;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The values a formula reads about one Character, on a sheet of reflexes (default 4), a role, hp (a
 * track with a fixed max of 40) and two derived fields: dodge = reflexes * 2 and broken = reflexes / 0.
 */
#[CoversClass(SheetValues::class)]
final class SheetValuesTest extends TestCase
{
    /**
     * @return iterable<string, array{Reference, int}>
     */
    public static function readableValues(): iterable
    {
        yield 'a number field the Character set' => [Reference::field('reflexes'), 7];
        yield 'a track current' => [Reference::field('hp'), 10];
        yield 'a track max' => [Reference::trackMax('hp'), 35];
        yield 'a derived field' => [Reference::field('dodge'), 14];
        yield 'a Tracker' => [Reference::tracker('chaos'), 6];
    }

    #[Test]
    #[DataProvider('readableValues')]
    public function itReadsEveryKindOfValueOfTheSheet(Reference $reference, int $expected): void
    {
        $values = SheetValues::of($this->sheet(), ['reflexes' => 7, 'hp' => ['current' => 10, 'max' => 35]], ['chaos' => 6]);

        self::assertSame($expected, $values->value($reference));
    }

    #[Test]
    public function aFieldTheCharacterHoldsNoValueForReadsAsItsDefault(): void
    {
        $values = SheetValues::of($this->sheet(), [], []);

        self::assertSame([4, 40, 40, 8], [
            $values->value(Reference::field('reflexes')),
            $values->value(Reference::field('hp')),
            $values->value(Reference::trackMax('hp')),
            $values->value(Reference::field('dodge')),
        ]);
    }

    /**
     * @return iterable<string, array{Reference}>
     */
    public static function referencesWithoutAValue(): iterable
    {
        yield 'a text field' => [Reference::field('role')];
        yield 'an unknown field' => [Reference::field('ghost')];
        yield 'the max of a number field' => [Reference::trackMax('reflexes')];
        yield 'a Tracker with no value' => [Reference::tracker('heat')];
        yield 'a derived field that failed' => [Reference::field('broken')];
        yield 'a check value, even named like a field' => [Reference::check('reflexes')];
    }

    #[Test]
    #[DataProvider('referencesWithoutAValue')]
    public function aReferenceWithNoValueFailsToEvaluate(Reference $reference): void
    {
        $values = SheetValues::of($this->sheet(), ['reflexes' => 5], ['chaos' => 6]);

        $this->expectException(FormulaEvaluationFailed::class);
        $this->expectExceptionMessageIsOrContains(\sprintf('The formula reads "%s", which has no value.', $reference->toString()));

        $values->value($reference);
    }

    #[Test]
    public function itListsTheDerivedValuesInSheetOrder(): void
    {
        self::assertSame(['broken' => null, 'dodge' => 10], SheetValues::of($this->sheet(), ['reflexes' => 5], [])->derived());
    }

    private function sheet(): SheetTemplate
    {
        return new SheetTemplate([
            new TextField('role', 'Role'),
            new NumberField('reflexes', 'Reflexes', default: 4),
            new TrackField('hp', 'HP', max: 40),
            new DerivedField('broken', 'Broken', null, null, Formula::fromString('{reflexes} / 0')),
            new DerivedField('dodge', 'Dodge', null, null, Formula::fromString('{reflexes} * 2')),
        ]);
    }
}
