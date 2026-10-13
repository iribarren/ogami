<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\Flow\CharacterStep;
use App\Play\Domain\GameSystem\Flow\ConditionStep;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\Flow\PickStep;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\Sheet\Field;
use App\Play\Domain\GameSystem\Sheet\ListField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TextField;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer reads the character sheet of schema version 2 (ADR 0022) and fails
 * closed on a field Studio would have rejected, and on the parts it does not play yet.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
#[CoversClass(SheetTemplate::class)]
#[CoversClass(NumberField::class)]
#[CoversClass(TextField::class)]
#[CoversClass(ListField::class)]
#[CoversClass(TrackField::class)]
final class GameSystemReleaseTranslatorSheetTest extends TestCase
{
    /**
     * @param array<mixed> $content
     */
    private function translate(array $content, int $schemaVersion = 2): GameSystemSnapshot
    {
        /** @var array<string, mixed> $typed */
        $typed = $content;
        $view = ReleaseViews::of($typed, schemaVersion: $schemaVersion);

        return new GameSystemReleaseTranslator()->translate($view);
    }

    /**
     * @return array<mixed>
     */
    private function withFields(mixed $fields): array
    {
        return ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-minimal'), 'sheet', ['fields' => $fields]);
    }

    /**
     * The sheet of valid/v2-sheet without its Scene Types, whose character step Play fails closed on.
     *
     * @return array<mixed>
     */
    private function sheetOnly(): array
    {
        return ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-sheet'), 'sceneTypes', []);
    }

    #[Test]
    public function itReadsEachFieldTypeWithItsLimitsAndDefaults(): void
    {
        $sheet = $this->translate($this->sheetOnly())->sheet();

        self::assertEquals(
            [
                new TextField('role', 'Role', 'What you do for the crew.', 'Identity'),
                new TextField('look', 'Look', null, 'Identity', true, 2000),
                new NumberField('reflexes', 'Reflexes', null, 'Stats', 1, 10, 4),
                new NumberField('luck', 'Luck', null, 'Stats'),
                new NumberField('debt', 'Debt', null, null, -1_000_000, 1_000_000, 0),
                new TrackField('hp', 'Hit points', null, 'Health', 40),
                new TrackField('humanity', 'Humanity', null, 'Health'),
                new ListField('gear', 'Gear', null, 'Belongings', 'Item', 20),
                new ListField('cyberware', 'Cyberware', null, 'Belongings'),
            ],
            $sheet->fields(),
        );
        self::assertNull($sheet->field('ghost'));
    }

    #[Test]
    public function itResolvesTheDefaultsOfAFieldWithoutLimits(): void
    {
        $sheet = $this->translate($this->sheetOnly())->sheet();
        $role = $sheet->field('role');
        $look = $sheet->field('look');
        $gear = $sheet->field('gear');
        $cyberware = $sheet->field('cyberware');
        $reflexes = $sheet->field('reflexes');
        $luck = $sheet->field('luck');
        self::assertInstanceOf(TextField::class, $role);
        self::assertInstanceOf(TextField::class, $look);
        self::assertInstanceOf(ListField::class, $gear);
        self::assertInstanceOf(ListField::class, $cyberware);
        self::assertInstanceOf(NumberField::class, $reflexes);
        self::assertInstanceOf(NumberField::class, $luck);

        self::assertSame(200, $role->limit());
        self::assertSame(2000, $look->limit());
        self::assertSame(50, $cyberware->limit());
        self::assertSame(20, $gear->limit());
        self::assertSame(4, $reflexes->initial());
        self::assertSame(0, $luck->initial());
        self::assertSame(3, new NumberField('a', 'A', min: 3, max: 9)->initial());
        self::assertSame(-2, new NumberField('a', 'A', max: -2)->initial());
    }

    #[Test]
    public function aReleaseWithoutFieldsHasAnEmptySheet(): void
    {
        self::assertTrue($this->translate(ReleaseViews::fixtureContent('valid/v2-minimal'))->sheet()->isEmpty());
        self::assertTrue($this->translate($this->withFields([]))->sheet()->isEmpty());
        self::assertTrue($this->translate($this->withFields(null))->sheet()->isEmpty());
        self::assertTrue($this->translate(ReleaseViews::contractDocExampleContent(), 1)->sheet()->isEmpty(), 'A schema version 1 release has an empty sheet.');
        self::assertTrue(new SheetTemplate()->isEmpty());
    }

    #[Test]
    public function itReadsTheSheetOfTheWestMarchesExample(): void
    {
        $sheet = $this->translate(ReleaseViews::fixtureContent('examples/west-marches'))->sheet();

        self::assertSame(['class', 'level', 'hp', 'notes', 'gear', 'treasure'], array_map(static fn (Field $field): string => $field->key, $sheet->fields()));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function badFields(): iterable
    {
        $number = ['key' => 'luck', 'label' => 'Luck', 'type' => 'number'];
        $text = ['key' => 'role', 'label' => 'Role', 'type' => 'text'];
        $list = ['key' => 'gear', 'label' => 'Gear', 'type' => 'list'];
        $track = ['key' => 'hp', 'label' => 'HP', 'type' => 'track'];

        yield 'fields not a list' => [['luck' => $number], 'sheet.fields: must be a list.'];
        yield 'too many fields' => [array_map(static fn (int $i): array => ['key' => 'f-'.$i] + $number, range(1, 101)), 'sheet.fields: at most 100 fields.'];
        yield 'a field that is not an object' => [['luck'], 'sheet.fields[0]: must be an object.'];
        yield 'unknown type' => [[['type' => 'dial'] + $number], 'sheet.fields[0].type: unknown field type "dial".'];
        yield 'no type' => [[['key' => 'luck', 'label' => 'Luck']], 'sheet.fields[0].type: must be a string.'];
        yield 'a prop of another type' => [[['maxLength' => 10] + $number], 'sheet.fields[0].maxLength: unknown property.'];
        yield 'duplicate key' => [[$number, $text, ['label' => 'Again'] + $number], 'sheet.fields[2].key: duplicate field key "luck".'];
        yield 'bad key' => [[['key' => 'Luck!'] + $number], 'sheet.fields[0].key: must be 1 to 64 characters among a-z, 0-9 and "-".'];
        yield 'blank label' => [[['label' => '  '] + $number], 'sheet.fields[0].label: must not be blank.'];
        yield 'label over the limit' => [[['label' => str_repeat('l', 101)] + $number], 'sheet.fields[0].label: must be at most 100 characters.'];
        yield 'hint over the limit' => [[['hint' => str_repeat('h', 501)] + $number], 'sheet.fields[0].hint: must be at most 500 characters.'];
        yield 'group over the limit' => [[['group' => str_repeat('g', 61)] + $number], 'sheet.fields[0].group: must be at most 60 characters.'];
        yield 'blank group' => [[['group' => ' '] + $number], 'sheet.fields[0].group: must not be blank.'];
        yield 'min not an integer' => [[['min' => '1'] + $number], 'sheet.fields[0].min: must be an integer.'];
        yield 'min below the limit' => [[['min' => -1_000_001] + $number], 'sheet.fields[0].min: must be between -1000000 and 1000000.'];
        yield 'max above the limit' => [[['max' => 1_000_001] + $number], 'sheet.fields[0].max: must be between -1000000 and 1000000.'];
        yield 'min above max' => [[['min' => 5, 'max' => 2] + $number], 'sheet.fields[0].max: must be at least min (5).'];
        yield 'default below min' => [[['min' => 1, 'default' => 0] + $number], 'sheet.fields[0].default: must be within min..max.'];
        yield 'default above max' => [[['max' => 3, 'default' => 4] + $number], 'sheet.fields[0].default: must be within min..max.'];
        yield 'multiline not a boolean' => [[['multiline' => 'yes'] + $text], 'sheet.fields[0].multiline: must be a boolean.'];
        yield 'maxLength zero' => [[['maxLength' => 0] + $text], 'sheet.fields[0].maxLength: must be between 1 and 2000.'];
        yield 'maxLength above the limit' => [[['maxLength' => 2001] + $text], 'sheet.fields[0].maxLength: must be between 1 and 2000.'];
        yield 'blank item label' => [[['itemLabel' => ' '] + $list], 'sheet.fields[0].itemLabel: must not be blank.'];
        yield 'item label over the limit' => [[['itemLabel' => str_repeat('i', 61)] + $list], 'sheet.fields[0].itemLabel: must be at most 60 characters.'];
        yield 'maxItems zero' => [[['maxItems' => 0] + $list], 'sheet.fields[0].maxItems: must be between 1 and 100.'];
        yield 'maxItems above the limit' => [[['maxItems' => 101] + $list], 'sheet.fields[0].maxItems: must be between 1 and 100.'];
        yield 'track max zero' => [[['max' => 0] + $track], 'sheet.fields[0].max: must be between 1 and 999.'];
        yield 'track max above the limit' => [[['max' => 1000] + $track], 'sheet.fields[0].max: must be between 1 and 999.'];
    }

    #[Test]
    #[DataProvider('badFields')]
    public function itFailsClosedOnFieldsStudioWouldHaveRejected(mixed $fields, string $message): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->translate($this->withFields($fields));
    }

    #[Test]
    public function itFailsClosedOnAnUnknownSheetProperty(): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('sheet.layout: unknown property.');

        $this->translate(ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-minimal'), 'sheet', ['layout' => 'grid']));
    }

    #[Test]
    public function itAcceptsFieldsExactlyAtTheLimits(): void
    {
        $fields = [
            ['key' => 'a', 'label' => str_repeat('l', 100), 'hint' => str_repeat('h', 500), 'group' => str_repeat('g', 60), 'type' => 'number', 'min' => -1_000_000, 'max' => 1_000_000, 'default' => 1_000_000],
            ['key' => 'b', 'label' => 'B', 'type' => 'text', 'maxLength' => 2000],
            ['key' => 'c', 'label' => 'C', 'type' => 'list', 'itemLabel' => str_repeat('i', 60), 'maxItems' => 100],
            ['key' => 'd', 'label' => 'D', 'type' => 'track', 'max' => 999],
        ];

        self::assertCount(4, $this->translate($this->withFields($fields))->sheet()->fields());
    }

    #[Test]
    public function itReadsTheCharacterStepWithTheCommonProperties(): void
    {
        $step = $this->translate(ReleaseViews::fixtureContent('valid/v2-sheet'))->sceneType('scene')?->setup->steps[0] ?? null;

        self::assertEquals(new CharacterStep('hero', 'Who are you?', 'Create your Character or pick one.', 'Fill the sheet.', true, null, []), $step);
    }

    #[Test]
    public function itReadsCharacterPicksAndCounts(): void
    {
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-sheet'), 'sceneTypes.0.setup', [['key' => 'hero', 'kind' => 'prompt', 'title' => 'Hero']]);

        $play = $this->translate($content)->sceneType('scene')?->play->steps ?? [];

        $pick = $play[0] ?? null;
        $condition = $play[1] ?? null;
        self::assertInstanceOf(PickStep::class, $pick);
        self::assertSame([ItemKind::Character, ['crew']], [$pick->of, $pick->tags]);
        self::assertInstanceOf(ConditionStep::class, $condition);
        self::assertSame([ItemKind::Character, ['crew', 'leader']], [$condition->count?->of, $condition->count?->tags]);
    }

    #[Test]
    public function itStillRefusesAnUnknownItemKind(): void
    {
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-sheet'), 'sceneTypes.0.setup', [['key' => 'hero', 'kind' => 'prompt', 'title' => 'Hero']]);
        $content = ReleaseArrays::with($content, 'sceneTypes.0.play.0.of', 'monster');

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('sceneTypes[0].play[0].of: unknown item kind "monster".');

        $this->translate($content);
    }
}
