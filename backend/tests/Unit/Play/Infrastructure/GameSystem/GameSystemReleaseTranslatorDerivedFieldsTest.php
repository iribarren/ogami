<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Formula\Domain\Formula;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer reads the derived fields of a sheet (ADR 0023) and re-checks what
 * Studio checks on publish: the formula parses, reads only number, track and derived fields and
 * the release's Trackers, and the derived fields do not read each other in a cycle.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
#[CoversClass(DerivedField::class)]
final class GameSystemReleaseTranslatorDerivedFieldsTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        return [
            ['key' => 'str', 'label' => 'Strength', 'type' => 'number'],
            ['key' => 'role', 'label' => 'Role', 'type' => 'text'],
            ['key' => 'gear', 'label' => 'Gear', 'type' => 'list'],
            ['key' => 'hp', 'label' => 'HP', 'type' => 'track'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $extra
     */
    private function translate(array $extra): GameSystemSnapshot
    {
        $content = ReleaseViews::fixtureContent('valid/v2-minimal');
        $content = ReleaseArrays::with($content, 'trackers', [['key' => 'chaos', 'name' => 'Chaos', 'kind' => 'counter', 'min' => 1, 'max' => 9, 'initial' => 5]]);
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with($content, 'sheet', ['fields' => [...$this->fields(), ...$extra]]);

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content));
    }

    #[Test]
    public function itReadsDerivedFieldsThatReadFieldsTracksTrackersAndOtherDerivedFields(): void
    {
        $snapshot = $this->translate([
            ['key' => 'total', 'label' => 'Total', 'hint' => 'All of it', 'group' => 'Stats', 'type' => 'derived', 'formula' => '{str} + {hp} + {hp.max} + {tracker:chaos} + {bonus}'],
            ['key' => 'bonus', 'label' => 'Bonus', 'type' => 'derived', 'formula' => 'max({str}, 2) / 2'],
        ]);

        $total = $snapshot->sheet()->field('total');
        self::assertInstanceOf(DerivedField::class, $total);
        self::assertEquals(Formula::fromString('{str} + {hp} + {hp.max} + {tracker:chaos} + {bonus}'), $total->formula);
        self::assertSame(['All of it', 'Stats'], [$total->hint, $total->group]);
        self::assertSame(['bonus', 'total'], $snapshot->sheet()->derivedOrder(), 'A derived field comes after the ones it reads.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badFormulas(): iterable
    {
        yield 'a syntax error' => ['{str} +', 'sheet.fields[4].formula: Unexpected end of the formula'];
        yield 'an unknown field' => ['{ghost}', 'sheet.fields[4].formula: {ghost}: unknown field "ghost".'];
        yield 'a text field' => ['{role}', 'sheet.fields[4].formula: {role}: field "role" is a text or list field, a formula reads number, track and derived fields only.'];
        yield 'a list field' => ['{gear}', 'sheet.fields[4].formula: {gear}: field "gear" is a text or list field, a formula reads number, track and derived fields only.'];
        yield 'a max of a number' => ['{str.max}', 'sheet.fields[4].formula: {str.max}: field "str" is not a track field, only a track field has a max.'];
        yield 'a max of an unknown field' => ['{ghost.max}', 'sheet.fields[4].formula: {ghost.max}: unknown field "ghost".'];
        yield 'an undeclared tracker' => ['{tracker:nope}', 'sheet.fields[4].formula: {tracker:nope}: unknown tracker "nope".'];
        yield 'a check reference' => ['{check:difficulty}', 'sheet.fields[4].formula: {check:difficulty}: a check reference is read only by the bands of a check.'];
        yield 'a check reference named like a field' => ['{check:str}', 'sheet.fields[4].formula: {check:str}: a check reference is read only by the bands of a check.'];
        yield 'itself' => ['{self}', 'sheet.fields[4].formula: Derived values depend on each other in a cycle: self -> self.'];
    }

    #[Test]
    #[DataProvider('badFormulas')]
    public function itFailsClosedOnAFormulaStudioWouldHaveRejected(string $formula, string $message): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->translate([['key' => 'self', 'label' => 'Self', 'type' => 'derived', 'formula' => $formula]]);
    }

    #[Test]
    public function itFailsClosedOnDerivedFieldsThatReadEachOther(): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('sheet.fields[4].formula: ');

        $this->translate([
            ['key' => 'a', 'label' => 'A', 'type' => 'derived', 'formula' => '{b} + 1'],
            ['key' => 'b', 'label' => 'B', 'type' => 'derived', 'formula' => '{a} + 1'],
        ]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badShapes(): iterable
    {
        $derived = ['key' => 'bonus', 'label' => 'Bonus', 'type' => 'derived'];

        yield 'no formula' => [$derived, 'sheet.fields[4].formula: must be a string.'];
        yield 'a formula that is not a string' => [$derived + ['formula' => 3], 'sheet.fields[4].formula: must be a string.'];
        yield 'a property of another type' => [$derived + ['formula' => '1', 'min' => 1], 'sheet.fields[4].min: unknown property.'];
        yield 'a formula over the limit' => [$derived + ['formula' => str_repeat('1+', 100).'1'], 'sheet.fields[4].formula: '];
    }

    /**
     * @param array<string, mixed> $field
     */
    #[Test]
    #[DataProvider('badShapes')]
    public function itFailsClosedOnAMalformedDerivedField(array $field, string $message): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->translate([$field]);
    }
}
