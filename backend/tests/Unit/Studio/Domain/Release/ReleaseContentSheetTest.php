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
}
