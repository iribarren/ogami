<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\PointBudget;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Several point budgets on a sheet (ADR 0043): `sheet.pointBudgets`, its canonical form (one budget
 * is the legacy `pointBudget`, so every hash stays) and its refusals with indexed paths.
 */
#[CoversClass(PointBudget::class)]
final class ReleaseContentPointBudgetsTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        return [
            ['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'min' => 1, 'max' => 99, 'roll' => ['dice' => '3d6*5']],
            ['key' => 'con', 'label' => 'Constitution', 'type' => 'number', 'min' => 1, 'max' => 99],
            ['key' => 'lore', 'label' => 'Lore', 'type' => 'number', 'min' => 0, 'max' => 5],
            ['key' => 'half', 'label' => 'Half', 'type' => 'derived', 'formula' => '({str}+{con})/10'],
        ];
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private static function attributes(array $override = []): array
    {
        return $override + ['key' => 'attributes', 'label' => 'Attribute points', 'total' => 12, 'fields' => ['str', 'con'], 'min' => 2, 'max' => 5];
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

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<mixed>
     */
    private function release(array $extra): array
    {
        return ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-minimal'), 'sheet', ['fields' => $this->fields()] + $extra);
    }

    /**
     * @param array<mixed> $release
     */
    private function assertRefused(array $release, string $message): void
    {
        try {
            ReleaseContent::fromArray($release);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringContainsString($message, $invalid->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sheetOf(ReleaseContent $content): array
    {
        /** @var array{sheet: array<string, mixed>} $canonical */
        $canonical = $content->toArray();

        return $canonical['sheet'];
    }

    #[Test]
    public function twoBudgetsStayAListInOrderAndRoundTripToTheSameHash(): void
    {
        $content = ReleaseContent::fromArray($this->release(['pointBudgets' => [self::attributes(), self::skills()]]));
        $sheet = $this->sheetOf($content);

        self::assertSame(['fields', 'pointBudgets'], array_keys($sheet));
        self::assertSame(['attributes', 'skills'], array_column((array) $sheet['pointBudgets'], 'key'));
        self::assertSame(['key', 'label', 'total', 'fields', 'min', 'max'], array_keys((array) ((array) $sheet['pointBudgets'])[0]));
        self::assertSame(['key', 'label', 'total', 'fields', 'max'], array_keys((array) ((array) $sheet['pointBudgets'])[1]));

        /** @var array<mixed> $decoded */
        $decoded = json_decode(json_encode($content->toArray(), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($content->hash(), ReleaseContent::fromArray($decoded)->hash());
    }

    #[Test]
    public function oneBudgetInTheListCanonicalizesToTheLegacyShapeAndHash(): void
    {
        $legacy = ReleaseContent::fromArray($this->release(['pointBudget' => ['label' => 'Attribute points', 'total' => 12, 'fields' => ['str', 'con'], 'min' => 2, 'max' => 5]]));
        $listed = ReleaseContent::fromArray($this->release(['pointBudgets' => [self::attributes()]]));

        self::assertSame($legacy->hash(), $listed->hash());
        self::assertEquals($legacy->toArray(), $listed->toArray());
        self::assertArrayNotHasKey('pointBudgets', $this->sheetOf($listed));
    }

    #[Test]
    public function aLabelLessLegacyBudgetKeepsItsShapeAndHash(): void
    {
        $content = ReleaseContent::fromArray($this->release(['pointBudget' => ['total' => 12, 'fields' => ['str']]]));

        self::assertSame(['total' => 12, 'fields' => ['str']], $this->sheetOf($content)['pointBudget']);
        self::assertSame($content->hash(), ReleaseContent::fromArray($content->toArray())->hash(), 'A republish of the stored content is the same content.');
    }

    #[Test]
    public function anEmptyOrNullListIsNoBudget(): void
    {
        $plain = ReleaseContent::fromArray($this->release([]));

        self::assertSame($plain->hash(), ReleaseContent::fromArray($this->release(['pointBudgets' => []]))->hash());
        self::assertSame($plain->hash(), ReleaseContent::fromArray($this->release(['pointBudgets' => null]))->hash());
        $withLegacy = ReleaseContent::fromArray($this->release(['pointBudget' => ['total' => 12, 'fields' => ['str']]]));
        self::assertSame($withLegacy->hash(), ReleaseContent::fromArray($this->release(['pointBudget' => ['total' => 12, 'fields' => ['str']], 'pointBudgets' => []]))->hash());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedLists(): iterable
    {
        $legacy = ['total' => 12, 'fields' => ['str']];
        yield 'both shapes' => [['pointBudget' => $legacy, 'pointBudgets' => [self::attributes()]], 'sheet.pointBudgets: '];
        yield 'not a list' => [['pointBudgets' => self::attributes()], 'sheet.pointBudgets: must be a list'];
        yield 'too many budgets' => [['pointBudgets' => array_map(static fn (int $i): array => self::attributes(['key' => 'b'.$i]), range(1, 11))], 'sheet.pointBudgets: at most 10'];
        yield 'an entry that is not an object' => [['pointBudgets' => [5]], 'sheet.pointBudgets[0]: must be an object'];
        yield 'an unknown property' => [['pointBudgets' => [self::attributes(['spare' => 1])]], 'sheet.pointBudgets[0].spare: unknown property'];
        yield 'no key' => [['pointBudgets' => [array_diff_key(self::attributes(), ['key' => 1])]], 'sheet.pointBudgets[0].key: required'];
        yield 'a key that is not a key' => [['pointBudgets' => [self::attributes(), self::skills(['key' => 'Bad Key'])]], 'sheet.pointBudgets[1].key'];
        yield 'a key that is not text' => [['pointBudgets' => [self::attributes(['key' => 7])]], 'sheet.pointBudgets[0].key: must be a string'];
        yield 'a duplicate key' => [['pointBudgets' => [self::attributes(), self::skills(['key' => 'attributes'])]], 'sheet.pointBudgets[1].key: duplicate budget key "attributes"'];
        yield 'no label' => [['pointBudgets' => [self::attributes(), array_diff_key(self::skills(), ['label' => 1])]], 'sheet.pointBudgets[1].label: required'];
        yield 'a blank label' => [['pointBudgets' => [self::attributes(['label' => '  '])]], 'sheet.pointBudgets[0].label: must not be blank'];
        yield 'a long label' => [['pointBudgets' => [self::attributes(['label' => str_repeat('a', 61)])]], 'sheet.pointBudgets[0].label'];
        yield 'a total of zero' => [['pointBudgets' => [self::attributes(), self::skills(['total' => 0])]], 'sheet.pointBudgets[1].total'];
        yield 'a total that is not a number' => [['pointBudgets' => [self::attributes(['total' => 'many'])]], 'sheet.pointBudgets[0].total: must be an integer'];
        yield 'no fields' => [['pointBudgets' => [self::attributes(['fields' => []])]], 'sheet.pointBudgets[0].fields'];
        yield 'an unknown field' => [['pointBudgets' => [self::attributes(), self::skills(['fields' => ['nope']])]], 'sheet.pointBudgets[1].fields[0]: unknown field'];
        yield 'a field that is not a number' => [['pointBudgets' => [self::attributes(['fields' => ['half']])]], 'sheet.pointBudgets[0].fields[0]: field "half" is a derived field'];
        yield 'a field that is not text' => [['pointBudgets' => [self::attributes(['fields' => ['str', 4]])]], 'sheet.pointBudgets[0].fields[1]: must be a string'];
        yield 'a repeated field' => [['pointBudgets' => [self::attributes(['fields' => ['str', 'str']])]], 'sheet.pointBudgets[0].fields[1]: duplicate field "str"'];
        yield 'a field in two budgets' => [['pointBudgets' => [self::attributes(), self::skills(['fields' => ['lore', 'con']])]], 'sheet.pointBudgets[1].fields[1]: field "con" is already in budget "attributes"'];
        yield 'min above max' => [['pointBudgets' => [self::attributes(), self::skills(['min' => 4])]], 'sheet.pointBudgets[1].max: must be at least min (4), 3 given'];
        yield 'bounds no field value fits' => [['pointBudgets' => [self::attributes(), self::skills(['min' => 6, 'max' => 7])]], 'sheet.pointBudgets[1].fields[0]: "lore" allows 0..5, no value fits the budget bounds 6..7'];
        yield 'a total below the lowest values' => [['pointBudgets' => [self::attributes(['total' => 3]), self::skills()]], 'sheet.pointBudgets[0].total: 3 is below 4'];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[Test]
    #[DataProvider('refusedLists')]
    public function aBudgetListThatBreaksTheContractIsRefusedAtItsIndexedPath(array $extra, string $message): void
    {
        $this->assertRefused($this->release($extra), $message);
    }

    #[Test]
    public function eachBudgetIsSpendableAgainstItsOwnBounds(): void
    {
        // The attributes need at least 2 each, the skill none: each total is held against its own bounds.
        ReleaseContent::fromArray($this->release(['pointBudgets' => [self::attributes(['total' => 4]), self::skills(['total' => 1])]]));

        $this->assertRefused($this->release(['pointBudgets' => [self::attributes(['total' => 3]), self::skills()]]), 'sheet.pointBudgets[0].total');
    }
}
