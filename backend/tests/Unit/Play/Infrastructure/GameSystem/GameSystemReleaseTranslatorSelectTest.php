<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\Sheet\SelectField;
use App\Play\Domain\GameSystem\Sheet\SelectOption;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer reads the select sheet field (ADR 0048) from valid/v2-select-field and
 * fails closed on what Studio would have rejected.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
#[CoversClass(SelectField::class)]
final class GameSystemReleaseTranslatorSelectTest extends TestCase
{
    /**
     * @param array<mixed> $content
     */
    private function translate(array $content): GameSystemSnapshot
    {
        /** @var array<string, mixed> $typed */
        $typed = $content;

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($typed));
    }

    #[Test]
    public function itReadsSelectFieldsWithAndWithoutADefault(): void
    {
        $sheet = $this->translate(ReleaseViews::fixtureContent('valid/v2-select-field'))->sheet();

        self::assertEquals(
            new SelectField('stance', 'Stance', 'How the Character fights.', options: [new SelectOption('guarded', 'Guarded'), new SelectOption('reckless', 'Reckless')], default: 'guarded'),
            $sheet->field('stance'),
        );
        self::assertEquals(
            new SelectField('origin', 'Origin', options: [new SelectOption('coast', 'Coast'), new SelectOption('hills', 'Hills'), new SelectOption('city', 'City')]),
            $sheet->field('origin'),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badFields(): iterable
    {
        $select = ['key' => 'stance', 'label' => 'Stance', 'type' => 'select', 'options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']]];

        yield 'no options' => [['options' => []] + $select, 'sheet.fields[0].options: must list between 1 and 50 options.'];
        yield 'missing options' => [array_diff_key($select, ['options' => 1]), 'sheet.fields[0].options: must be a list.'];
        yield '51 options' => [['options' => array_map(static fn (int $i): array => ['key' => 'o-'.$i, 'label' => 'O'], range(1, 51))] + $select, 'sheet.fields[0].options: must list between 1 and 50 options.'];
        yield 'a repeated option key' => [['options' => [['key' => 'a', 'label' => 'A'], ['key' => 'a', 'label' => 'B']]] + $select, 'sheet.fields[0].options[1].key: duplicate option key "a".'];
        yield 'a default that is not an option' => [['default' => 'ghost'] + $select, 'sheet.fields[0].default: Select field "stance" has a default that is not one of its options.'];
        yield 'a default that is not a key' => [['default' => 'Not A Key'] + $select, 'sheet.fields[0].default: must be 1 to 64 characters'];
        yield 'a default that is a number' => [['default' => 1] + $select, 'sheet.fields[0].default: must be a string.'];
        yield 'a property of another type' => [['max' => 3] + $select, 'sheet.fields[0].max: unknown property.'];
    }

    /**
     * @param array<string, mixed> $field
     */
    #[Test]
    #[DataProvider('badFields')]
    public function itFailsClosedOnFieldsStudioWouldHaveRejected(array $field, string $message): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->translate(ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-minimal'), 'sheet', ['fields' => [$field]]));
    }

    #[Test]
    public function itRefusesAFormulaAndASheetEffectOnASelectField(): void
    {
        $release = ReleaseViews::fixtureContent('valid/v2-select-field');
        $release = ReleaseArrays::with($release, 'sheet.fields.3', ['key' => 'power', 'label' => 'Power', 'type' => 'derived', 'formula' => '{stance}']);

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('field "stance" is a text, list, select or inventory field');

        $this->translate($release);
    }
}
