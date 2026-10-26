<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\Sheet\PointBudget;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer reads both point budget shapes into a list (ADR 0043): the legacy
 * `pointBudget` as the key "points", `pointBudgets` with each key. It re-checks what Studio checks
 * on publish and fails closed.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
#[CoversClass(SheetTemplate::class)]
#[CoversClass(PointBudget::class)]
final class GameSystemReleaseTranslatorBudgetsTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra
     */
    private function translate(array $extra): SheetTemplate
    {
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-minimal'), 'sheet', ['fields' => $this->fields()] + $extra);

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content))->sheet();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        return [
            ['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'min' => 1, 'max' => 99, 'roll' => ['dice' => '3d6*5']],
            ['key' => 'con', 'label' => 'Constitution', 'type' => 'number', 'min' => 1, 'max' => 99],
            ['key' => 'lore', 'label' => 'Lore', 'type' => 'number', 'min' => 0, 'max' => 5],
            ['key' => 'hp', 'label' => 'Hit points', 'type' => 'track', 'max' => 10],
        ];
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function attributes(array $override = []): array
    {
        return $override + ['key' => 'attributes', 'label' => 'Attribute points', 'total' => 100, 'fields' => ['str', 'con'], 'min' => 15, 'max' => 90];
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function skills(array $override = []): array
    {
        return $override + ['key' => 'skills', 'label' => 'Skill points', 'total' => 8, 'fields' => ['lore'], 'max' => 3];
    }

    #[Test]
    public function itReadsTheListKeyedAndInOrder(): void
    {
        $budgets = $this->translate(['pointBudgets' => [self::attributes(), self::skills()]])->budgets();

        self::assertEquals([
            new PointBudget(100, ['str', 'con'], 'Attribute points', 15, 90, 'attributes'),
            new PointBudget(8, ['lore'], 'Skill points', null, 3, 'skills'),
        ], $budgets);
    }

    #[Test]
    public function aLegacyBudgetIsTheKeyPointsWithOrWithoutALabel(): void
    {
        self::assertEquals([new PointBudget(100, ['str', 'con'], 'Points', 15, 90, 'points')], $this->translate(['pointBudget' => ['label' => 'Points', 'total' => 100, 'fields' => ['str', 'con'], 'min' => 15, 'max' => 90]])->budgets());
        self::assertEquals([new PointBudget(10, ['str'], key: 'points')], $this->translate(['pointBudget' => ['total' => 10, 'fields' => ['str']]])->budgets());
    }

    #[Test]
    public function aListOfOneAndAnEmptyOrNullListAreRead(): void
    {
        self::assertCount(1, $this->translate(['pointBudgets' => [self::attributes()]])->budgets());
        self::assertSame([], $this->translate(['pointBudgets' => []])->budgets());
        self::assertSame([], $this->translate(['pointBudgets' => null])->budgets());
        self::assertCount(1, $this->translate(['pointBudget' => ['total' => 10, 'fields' => ['str']], 'pointBudgets' => []])->budgets());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}> the sheet properties and the expected path and reason
     */
    public static function malformed(): iterable
    {
        yield 'both shapes' => [['pointBudget' => ['total' => 10, 'fields' => ['str']], 'pointBudgets' => [self::attributes()]], 'sheet.pointBudgets: cannot be combined with pointBudget.'];
        yield 'not a list' => [['pointBudgets' => self::attributes()], 'sheet.pointBudgets: must be a list.'];
        yield 'too many budgets' => [['pointBudgets' => array_map(static fn (int $i): array => self::attributes(['key' => 'b'.$i]), range(1, 11))], 'sheet.pointBudgets: at most 10 point budgets.'];
        yield 'an entry that is not an object' => [['pointBudgets' => [3]], 'sheet.pointBudgets[0]: must be an object.'];
        yield 'an unknown property' => [['pointBudgets' => [self::attributes(['spare' => 1])]], 'sheet.pointBudgets[0].spare: unknown property.'];
        yield 'no key' => [['pointBudgets' => [array_diff_key(self::attributes(), ['key' => 1])]], 'sheet.pointBudgets[0].key: required.'];
        yield 'a key that is not a key' => [['pointBudgets' => [self::attributes(), self::skills(['key' => 'Bad Key'])]], 'sheet.pointBudgets[1].key'];
        yield 'a duplicate key' => [['pointBudgets' => [self::attributes(), self::skills(['key' => 'attributes'])]], 'sheet.pointBudgets[1].key: duplicate budget key "attributes".'];
        yield 'no label' => [['pointBudgets' => [array_diff_key(self::attributes(), ['label' => 1])]], 'sheet.pointBudgets[0].label: required.'];
        yield 'a blank label' => [['pointBudgets' => [self::attributes(['label' => ' '])]], 'sheet.pointBudgets[0].label: must not be blank.'];
        yield 'a total of zero' => [['pointBudgets' => [self::attributes(), self::skills(['total' => 0])]], 'sheet.pointBudgets[1].total: must be between 1 and 1000000.'];
        yield 'no fields' => [['pointBudgets' => [self::attributes(['fields' => []])]], 'sheet.pointBudgets[0].fields: must list between 1 and 100 fields.'];
        yield 'a field that is not a key' => [['pointBudgets' => [self::attributes(['fields' => ['Str']])]], 'sheet.pointBudgets[0].fields[0]'];
        yield 'min above max' => [['pointBudgets' => [self::attributes(), self::skills(['min' => 5])]], 'sheet.pointBudgets[1].max: must be at least min (5).'];
        yield 'a track field' => [['pointBudgets' => [self::attributes(['fields' => ['hp']])]], 'sheet.pointBudgets: The point budget covers "hp", which is not a number field.'];
        yield 'a field repeated in one budget' => [['pointBudgets' => [self::attributes(['fields' => ['str', 'con', 'str']])]], 'sheet.pointBudgets: A point budget covers at least one field, each once.'];
        yield 'a field in two budgets' => [['pointBudgets' => [self::attributes(), self::skills(['fields' => ['lore', 'con']])]], 'sheet.pointBudgets: Field "con" is in budgets "attributes" and "skills".'];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[Test]
    #[DataProvider('malformed')]
    public function itFailsClosedOnAShapeItCannotRepresent(array $extra, string $reason): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('cannot be read by Play: '.$reason);

        $this->translate($extra);
    }
}
