<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\SheetFields;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The character sheet of schema version 2 (ADR 0022): additive, so releases without fields keep
 * their canonical form and hash; schema version 1 still reserves the sheet.
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(SheetFields::class)]
final class ReleaseContentSheetTest extends TestCase
{
    /** sha256 of the canonical JSON of valid/v2-minimal, computed before the sheet existed. */
    private const string MINIMAL_HASH = '8189494451e352609f8328f2e4d3b9fc19dc88b0d19e09782402d50b72be8b3f';

    #[Test]
    public function aReleaseWithoutFieldsKeepsItsCanonicalFormAndHash(): void
    {
        $content = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-minimal'));

        self::assertSame(self::MINIMAL_HASH, $content->hash());
        self::assertStringContainsString('"sheet":{},"checks":[]', json_encode($content->toArray(), \JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function emptySheets(): iterable
    {
        yield 'no fields key' => [[]];
        yield 'empty fields' => [['fields' => []]];
        yield 'null fields' => [['fields' => null]];
        yield 'empty object' => [new \stdClass()];
    }

    #[Test]
    #[DataProvider('emptySheets')]
    public function everyEmptySheetCanonicalizesToTheSameObject(mixed $sheet): void
    {
        $content = ReleaseContent::fromArray(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-minimal'), 'sheet', $sheet));

        self::assertSame(self::MINIMAL_HASH, $content->hash());
        self::assertEquals(new \stdClass(), $content->toArray()['sheet']);
    }

    #[Test]
    public function aSheetWithFieldsRoundTripsInCanonicalFormAndEncodesAsAnObject(): void
    {
        $content = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-sheet'));
        /** @var array{sheet: array{fields: list<array<string, mixed>>}} $canonical */
        $canonical = $content->toArray();

        $fields = $canonical['sheet']['fields'];
        self::assertCount(9, $fields);
        self::assertSame(['key', 'label', 'hint', 'group', 'type'], array_keys($fields[0]));
        self::assertSame(['key', 'label', 'group', 'type', 'min', 'max', 'default'], array_keys($fields[2]));
        self::assertStringContainsString('"sheet":{"fields":[{"key":"role"', json_encode($canonical, \JSON_THROW_ON_ERROR));

        /** @var array<mixed> $decoded */
        $decoded = json_decode(json_encode($canonical, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        $again = ReleaseContent::fromArray($decoded);
        self::assertSame($content->hash(), $again->hash());
        self::assertNotSame(self::MINIMAL_HASH, $content->hash());
    }

    #[Test]
    public function nullOptionalsAndAFalseMultilineAreLeftOutOfTheCanonicalField(): void
    {
        $field = ['key' => 'role', 'label' => 'Role', 'type' => 'text', 'hint' => null, 'group' => null, 'multiline' => false, 'maxLength' => null];
        $content = ReleaseContent::fromArray(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-minimal'), 'sheet', ['fields' => [$field]]));

        self::assertEquals(['fields' => [['key' => 'role', 'label' => 'Role', 'type' => 'text']]], $content->toArray()['sheet']);
    }

    #[Test]
    public function aNumberFieldMayBeBoundedByItsDefaultAlone(): void
    {
        $field = ['key' => 'luck', 'label' => 'Luck', 'type' => 'number', 'min' => 3, 'max' => 3, 'default' => 3];

        $content = ReleaseContent::fromArray(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-minimal'), 'sheet', ['fields' => [$field]]));

        self::assertEquals(['fields' => [$field]], $content->toArray()['sheet']);
    }

    #[Test]
    public function schemaVersionOneStillReservesTheSheet(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/minimal'), 'sheet', ['fields' => []]);

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('sheet: not supported in schema version 1, must be {}');

        ReleaseContent::fromArray($release);
    }

    #[Test]
    public function aCharacterStepAndCharacterPicksAreValidAndKeepTheirTags(): void
    {
        /** @var array{sceneTypes: list<array{setup: list<array<string, mixed>>, play: list<array<string, mixed>>}>} $canonical */
        $canonical = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-sheet'))->toArray();

        $setup = $canonical['sceneTypes'][0]['setup'];
        $play = $canonical['sceneTypes'][0]['play'];
        self::assertSame('character', $setup[0]['kind']);
        self::assertSame(['key', 'kind', 'title', 'prompt', 'tip', 'mandatory'], array_keys($setup[0]));
        self::assertSame('character', $play[0]['of']);
        self::assertSame(['crew'], $play[0]['tags']);
        self::assertSame(['of' => 'character', 'tags' => ['crew', 'leader']], $play[1]['count']);
    }

    #[Test]
    public function aPlaceholderMayReadACharacterStepButAnUnknownStepFails(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-sheet'), 'sceneTypes.0.setup.1.title', '{step:nobody} walks in.');

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('placeholder {step:nobody}: unknown step "nobody"');

        ReleaseContent::fromArray($release);
    }

    #[Test]
    public function aConditionsFieldRoundTripsInCanonicalFormWithItsOptionsInOrder(): void
    {
        $release = ReleaseArrays::fixture('valid/v2-dice-pools');
        $release = ReleaseArrays::with($release, 'sheet.fields.2.hint', null);
        $release = ReleaseArrays::with($release, 'sheet.fields.2.group', null);
        /** @var array{sheet: array{fields: list<array<string, mixed>>}} $canonical */
        $canonical = ReleaseContent::fromArray($release)->toArray();

        self::assertSame(['key', 'label', 'type', 'options'], array_keys($canonical['sheet']['fields'][2]));
        self::assertSame([['key' => 'hurt', 'label' => 'Hurt'], ['key' => 'exhausted', 'label' => 'Exhausted']], $canonical['sheet']['fields'][2]['options']);
    }

    /**
     * @return iterable<string, array{string, mixed, string}> the path changed in valid/v2-dice-pools, its new value and the message
     */
    public static function conditionsRefusals(): iterable
    {
        $option = static fn (int $n): array => ['key' => 'c'.$n, 'label' => 'Condition '.$n];

        yield 'no options' => ['sheet.fields.2.options', [], 'sheet.fields[2].options: at least 1 options, 0 given'];
        yield 'options that are not a list' => ['sheet.fields.2.options', 'hurt', 'sheet.fields[2].options: must be a list'];
        yield '21 options' => ['sheet.fields.2.options', array_map($option, range(1, 21)), 'sheet.fields[2].options: at most 20 options, 21 given'];
        yield 'a missing options list' => ['sheet.fields.3', ['key' => 'mental', 'label' => 'Mental', 'type' => 'conditions'], 'sheet.fields[3].options: required'];
        yield 'an option that is not an object' => ['sheet.fields.2.options.1', 'hurt', 'sheet.fields[2].options[1]: must be an object'];
        yield 'an option with an unknown property' => ['sheet.fields.2.options.1.color', 'red', 'sheet.fields[2].options[1].color: unknown property'];
        yield 'an option without a key' => ['sheet.fields.2.options.1', ['label' => 'Exhausted'], 'sheet.fields[2].options[1].key: required'];
        yield 'an option without a label' => ['sheet.fields.2.options.1', ['key' => 'exhausted'], 'sheet.fields[2].options[1].label: required'];
        yield 'an option key that breaks the key rule' => ['sheet.fields.2.options.1.key', 'Exhausted', 'sheet.fields[2].options[1].key: must be 1 to 64 characters'];
        yield 'a repeated option key' => ['sheet.fields.2.options.1.key', 'hurt', 'sheet.fields[2].options[1].key: duplicate option key "hurt"'];
        yield 'a blank option label' => ['sheet.fields.2.options.0.label', ' ', 'sheet.fields[2].options[0].label: must not be blank'];
        yield 'an option label over 60 characters' => ['sheet.fields.2.options.0.label', str_repeat('a', 61), 'sheet.fields[2].options[0].label: must be at most 60 characters'];
        yield 'a number property' => ['sheet.fields.2.max', 3, 'sheet.fields[2].max: unknown property'];
        yield 'options on a number field' => ['sheet.fields.0.options', [['key' => 'a', 'label' => 'A']], 'sheet.fields[0].options: unknown property'];
    }

    #[Test]
    #[DataProvider('conditionsRefusals')]
    public function aMalformedConditionsFieldIsRefusedWithItsPath(string $path, mixed $value, string $message): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-dice-pools'), $path, $value);

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        ReleaseContent::fromArray($release);
    }

    #[Test]
    public function theSameOptionKeyMayBeDeclaredByTwoConditionsFields(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-dice-pools'), 'sheet.fields.3.options.0.key', 'hurt');

        self::assertNotSame('', ReleaseContent::fromArray($release)->hash());
    }

    #[Test]
    public function aStartMaxFormulaAndAPointBudgetRefuseAConditionsField(): void
    {
        $track = ['key' => 'hp', 'label' => 'HP', 'type' => 'track', 'startMax' => ['formula' => '{physical}']];
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-dice-pools'), 'sheet.fields', [...$this->fields(), $track]);
        try {
            ReleaseContent::fromArray($release);
            self::fail('A start max formula reads number and derived fields only.');
        } catch (InvalidReleaseContent $exception) {
            self::assertStringContainsString('sheet.fields[5].startMax.formula: {physical}: field "physical" is a conditions field', $exception->getMessage());
        }

        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-dice-pools'), 'sheet.pointBudget', ['total' => 5, 'fields' => ['agility', 'physical']]);
        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('sheet.pointBudget.fields[1]: field "physical" is a conditions field, a budget covers number fields');

        ReleaseContent::fromArray($release);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(): array
    {
        /** @var array{sheet: array{fields: list<array<string, mixed>>}} $release */
        $release = ReleaseArrays::fixture('valid/v2-dice-pools');

        return $release['sheet']['fields'];
    }
}
