<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\GameSystem;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\SheetFieldInUse;
use App\Studio\Domain\GameSystem\SheetFieldKeyTaken;
use App\Studio\Domain\GameSystem\SheetFieldNotFound;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs against valid/v2-sheet-effects: fields pow, hp, sanity and bonus (derived from pow). pow is
 * read by bonus and by sheet effects, hp and sanity by sheet effects, sanity also by a check band,
 * bonus by a sheet effect. valid/v2-checks has hands and edge (derived from hands, read by a modifier).
 */
#[CoversClass(GameSystem::class)]
#[CoversClass(SheetFieldInUse::class)]
#[CoversClass(SheetFieldKeyTaken::class)]
#[CoversClass(SheetFieldNotFound::class)]
final class GameSystemSheetTest extends TestCase
{
    private \DateTimeImmutable $created;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->created = new \DateTimeImmutable('2026-10-15T10:00:00');
        $this->now = new \DateTimeImmutable('2026-10-16T10:00:00');
    }

    private function draft(string $fixture = 'valid/v2-sheet-effects'): GameSystem
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture($fixture))->toArray();

        return GameSystem::createFromRelease(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a71'), 'my-copy', 'My copy', null, 'user-1', $source, $this->created);
    }

    /**
     * @return array<string, mixed>
     */
    private function sheet(GameSystem $gameSystem): array
    {
        /** @var array<string, mixed> $sheet */
        $sheet = $gameSystem->document()['sheet'];

        return $sheet;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fields(GameSystem $gameSystem): array
    {
        /** @var list<array<string, mixed>> $fields */
        $fields = $this->sheet($gameSystem)['fields'] ?? [];

        return $fields;
    }

    /**
     * @return list<string>
     */
    private function keys(GameSystem $gameSystem): array
    {
        /** @var list<string> $keys */
        $keys = array_column($this->fields($gameSystem), 'key');

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private static function field(string $key = 'luck'): array
    {
        return ['key' => $key, 'label' => 'Luck', 'type' => 'number', 'min' => 0, 'max' => 10];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function assertUntouched(GameSystem $gameSystem, array $document): void
    {
        self::assertSame($document, $gameSystem->document());
        self::assertSame(1, $gameSystem->version());
        self::assertEquals($this->created, $gameSystem->updatedAt());
    }

    #[Test]
    public function aNewFieldIsAppendedAsGivenAndUpdatesTheDraft(): void
    {
        $gameSystem = $this->draft();

        $gameSystem->saveSheetField(null, self::field(), $this->now);

        self::assertSame(['pow', 'hp', 'sanity', 'bonus', 'luck'], $this->keys($gameSystem));
        self::assertSame(self::field(), $this->fields($gameSystem)[4]);
        self::assertEquals($this->now, $gameSystem->updatedAt());
        ReleaseContent::checkItemRules($gameSystem->document());
    }

    #[Test]
    public function aReplacementWithTheSameKeyChangesOnlyThatField(): void
    {
        $gameSystem = $this->draft();
        $expected = $this->fields($gameSystem);

        $gameSystem->saveSheetField('pow', [...self::field('pow'), 'label' => 'Power', 'default' => 5], $this->now);

        $expected[0] = [...self::field('pow'), 'label' => 'Power', 'default' => 5];
        self::assertSame($expected, $this->fields($gameSystem));
    }

    #[Test]
    public function anUnreferencedFieldIsRenamedInPlace(): void
    {
        $gameSystem = $this->draft('valid/v2-checks');
        $gameSystem->saveSheetField(null, self::field(), $this->now);
        $gameSystem->saveSheetField(null, self::field('other'), $this->now);

        $gameSystem->saveSheetField('luck', self::field('fortune'), $this->now);

        self::assertSame(['hands', 'edge', 'fortune', 'other'], $this->keys($gameSystem));
    }

    #[Test]
    public function aFieldKeptUnderItsKeyMayBeEditedWhileReferenced(): void
    {
        $gameSystem = $this->draft();

        $gameSystem->saveSheetField('hp', ['key' => 'hp', 'label' => 'Health', 'type' => 'track', 'max' => 20], $this->now);

        self::assertSame(['pow', 'hp', 'sanity', 'bonus'], $this->keys($gameSystem));
    }

    #[Test]
    public function aKeyOfAnotherFieldIsTaken(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();

        foreach ([[null, 'hp'], ['pow', 'hp']] as [$original, $key]) {
            try {
                $gameSystem->saveSheetField($original, self::field($key), $this->now);
                self::fail('Expected SheetFieldKeyTaken');
            } catch (SheetFieldKeyTaken $taken) {
                self::assertSame('The sheet already has a field "hp".', $taken->getMessage());
            }
        }

        $this->assertUntouched($gameSystem, $document);
    }

    #[Test]
    public function anUnknownOriginalKeyIsNotFound(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();

        $this->expectException(SheetFieldNotFound::class);
        try {
            $gameSystem->saveSheetField('ghost', self::field(), $this->now);
        } finally {
            $this->assertUntouched($gameSystem, $document);
        }
    }

    #[Test]
    public function aReferencedFieldCannotBeRenamedAndTheRefusalListsThePaths(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();

        try {
            $gameSystem->saveSheetField('pow', self::field('power'), $this->now);
            self::fail('Expected SheetFieldInUse');
        } catch (SheetFieldInUse $inUse) {
            self::assertSame([
                'sheet.fields[3].formula',
                'sceneTypes[0].setup[0].effects[0]',
                'sceneTypes[0].play[0].outcomes[0].effects[2]',
            ], $inUse->paths());
            self::assertStringStartsWith('The sheet field "pow" is used at sheet.fields[3].formula, ', $inUse->getMessage());
        }

        $this->assertUntouched($gameSystem, $document);
    }

    #[Test]
    public function aDerivedFieldMayBeRenamedWhenOnlyItsOwnFormulaNamesAnotherField(): void
    {
        $gameSystem = $this->draft('valid/v2-checks');
        $grit = ['key' => 'grit', 'label' => 'Grit', 'type' => 'derived', 'formula' => '{hands} + 1'];
        $gameSystem->saveSheetField(null, $grit, $this->now);

        $gameSystem->saveSheetField('grit', [...$grit, 'key' => 'mettle'], $this->now);

        self::assertSame(['hands', 'edge', 'mettle'], $this->keys($gameSystem));
    }

    #[Test]
    public function aTypeChangeThatBreaksASheetEffectIsRefusedWithItsPath(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();

        try {
            $gameSystem->saveSheetField('hp', ['key' => 'hp', 'label' => 'HP', 'type' => 'text'], $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith('sceneTypes[0].play[0].outcomes[0].effects[1].field: field "hp" is a text field', $invalid->getMessage());
        }

        $this->assertUntouched($gameSystem, $document);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidFields(): iterable
    {
        yield 'no type' => [['key' => 'luck', 'label' => 'Luck'], 'sheet.fields[4].type'];
        yield 'a bad key' => [['key' => 'Luck!', 'label' => 'Luck', 'type' => 'text'], 'sheet.fields[4].key'];
        yield 'a formula over an unknown field' => [['key' => 'luck', 'label' => 'Luck', 'type' => 'derived', 'formula' => '{ghost}'], 'sheet.fields[4].formula'];
        yield 'a group without declared groups' => [[...self::field(), 'span' => 2], 'sheet.fields[4].span'];
    }

    /**
     * @param array<string, mixed> $field
     * @param non-empty-string     $path
     */
    #[Test]
    #[DataProvider('invalidFields')]
    public function anInvalidFieldIsRefusedWithItsPathAndNothingChanges(array $field, string $path): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();

        try {
            $gameSystem->saveSheetField(null, $field, $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith($path, $invalid->getMessage());
        }

        $this->assertUntouched($gameSystem, $document);
    }

    #[Test]
    public function theLayoutOfTheSheetIsKeptAndItsRulesApplyToTheField(): void
    {
        $gameSystem = $this->draft('valid/v2-checks');
        $layout = ['columns' => 2, 'groups' => [['name' => 'Stats', 'columns' => 2, 'span' => 2]]];
        $document = ReleaseArrays::with($gameSystem->document(), 'sheet', [
            ...$layout,
            'fields' => [
                ['key' => 'hands', 'label' => 'Hands', 'group' => 'Stats', 'type' => 'number'],
                ['key' => 'edge', 'label' => 'Edge', 'group' => 'Stats', 'type' => 'derived', 'formula' => '{hands}/2'],
            ],
        ]);
        $gameSystem->restore($document, $this->created);

        $gameSystem->saveSheetField(null, [...self::field(), 'group' => 'Stats', 'span' => 2], $this->now);

        self::assertSame($layout, array_intersect_key($this->sheet($gameSystem), $layout));
        self::assertSame(['hands', 'edge', 'luck'], $this->keys($gameSystem));

        try {
            $gameSystem->saveSheetField(null, self::field('stray'), $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith('sheet.fields[3]', $invalid->getMessage());
        }

        self::assertSame(['hands', 'edge', 'luck'], $this->keys($gameSystem));
    }

    #[Test]
    public function aDraftWithoutASheetOrFieldsGetsOneOnSave(): void
    {
        foreach ([null, [], ['groups' => []]] as $sheet) {
            $gameSystem = $this->draft('valid/v2-minimal');
            $document = $gameSystem->document();
            if (null === $sheet) {
                unset($document['sheet']);
            } else {
                $document['sheet'] = $sheet;
            }

            $gameSystem->restore($document, $this->created);
            $gameSystem->saveSheetField(null, self::field(), $this->now);

            self::assertSame([...$sheet ?? [], 'fields' => [self::field()]], $gameSystem->document()['sheet']);
        }
    }

    #[Test]
    public function anUnreferencedFieldIsDeleted(): void
    {
        $gameSystem = $this->draft('valid/v2-checks');
        $gameSystem->saveSheetField(null, self::field(), $this->now);

        $gameSystem->deleteSheetField('luck', $this->now);

        self::assertSame(['hands', 'edge'], $this->keys($gameSystem));
    }

    #[Test]
    public function theLastFieldCanBeDeleted(): void
    {
        $gameSystem = $this->draft('valid/v2-minimal');
        $gameSystem->saveSheetField(null, self::field(), $this->now);

        $gameSystem->deleteSheetField('luck', $this->now);

        self::assertSame([], $this->keys($gameSystem));
    }

    #[Test]
    public function anOptionAnEffectMarksCannotBeRemovedOrRenamedAndTheRefusalNamesIt(): void
    {
        $gameSystem = $this->conditionsDraft();
        $document = $gameSystem->document();
        $field = $this->fieldNamed($gameSystem, 'physical');

        foreach ([
            'removed' => [['key' => 'exhausted', 'label' => 'Exhausted']],
            'renamed' => [['key' => 'wounded', 'label' => 'Hurt'], ['key' => 'exhausted', 'label' => 'Exhausted']],
        ] as $change => $options) {
            try {
                $gameSystem->saveSheetField('physical', [...$field, 'options' => $options], $this->now);
                self::fail('Expected SheetFieldInUse when an option is '.$change);
            } catch (SheetFieldInUse $inUse) {
                self::assertSame(['sceneTypes[0].play[0].outcomes[0].effects[0]', 'sceneTypes[0].play[0].outcomes[1].effects[1]', 'checks[0].push.effects[0]'], $inUse->paths());
                self::assertSame('The option "hurt" of the sheet field "physical" is used at sceneTypes[0].play[0].outcomes[0].effects[0], sceneTypes[0].play[0].outcomes[1].effects[1], checks[0].push.effects[0].', $inUse->getMessage());
            }
        }

        $this->assertUntouched($gameSystem, $document);
    }

    #[Test]
    public function aConditionsFieldCannotBecomeAnotherTypeWhileAnEffectMarksOrClearsItWhateverTheOption(): void
    {
        $gameSystem = $this->conditionsDraft();
        $document = $gameSystem->document();

        // "mental" has no option an effect names: its only effect clears all, and the push marks "physical" without an option.
        foreach (['mental' => 'sceneTypes[0].play[0].outcomes[1].effects[0]', 'physical' => 'checks[0].push.effects[0]'] as $key => $path) {
            try {
                $gameSystem->saveSheetField($key, ['key' => $key, 'label' => 'Changed', 'type' => 'number', 'min' => 0, 'max' => 5, 'default' => 0], $this->now);
                self::fail('Expected SheetFieldInUse for '.$key);
            } catch (SheetFieldInUse $inUse) {
                self::assertContains($path, $inUse->paths());
                self::assertStringStartsWith(\sprintf('The conditions field "%s" is used at ', $key), $inUse->getMessage());
            }
        }

        $this->assertUntouched($gameSystem, $document);
    }

    #[Test]
    public function anOptionNoEffectMarksCanBeRemovedAndTheOthersAddedRelabelledOrReordered(): void
    {
        $gameSystem = $this->conditionsDraft();
        $field = $this->fieldNamed($gameSystem, 'physical');

        // No effect names "exhausted": the mark and clears of the release name "hurt" or clear all.
        $gameSystem->saveSheetField('physical', [...$field, 'options' => [['key' => 'hurt', 'label' => 'Wounded'], ['key' => 'dazed', 'label' => 'Dazed']]], $this->now);

        self::assertSame(
            [['key' => 'hurt', 'label' => 'Wounded'], ['key' => 'dazed', 'label' => 'Dazed']],
            $this->fieldNamed($gameSystem, 'physical')['options'],
        );

        $gameSystem->saveSheetField('physical', [...$field, 'options' => [['key' => 'dazed', 'label' => 'Dazed'], ['key' => 'hurt', 'label' => 'Hurt']]], $this->now);

        /** @var list<array<string, string>> $options */
        $options = $this->fieldNamed($gameSystem, 'physical')['options'];
        self::assertSame(['dazed', 'hurt'], array_column($options, 'key'));
    }

    #[Test]
    public function aConditionsFieldAnEffectNamesCannotBeDeleted(): void
    {
        $gameSystem = $this->conditionsDraft();
        $document = $gameSystem->document();

        try {
            $gameSystem->deleteSheetField('physical', $this->now);
            self::fail('Expected SheetFieldInUse');
        } catch (SheetFieldInUse $inUse) {
            self::assertContains('checks[0].push.effects[0]', $inUse->paths());
            self::assertContains('sceneTypes[0].play[0].outcomes[1].effects[1]', $inUse->paths());
        }

        $this->assertUntouched($gameSystem, $document);
    }

    #[Test]
    public function aReferencedFieldCannotBeDeleted(): void
    {
        $gameSystem = $this->draft('valid/v2-checks');
        $document = $gameSystem->document();

        foreach (['hands' => ['sheet.fields[1].formula', 'checks[0].modifier'], 'edge' => ['checks[0].modifier']] as $key => $paths) {
            try {
                $gameSystem->deleteSheetField($key, $this->now);
                self::fail('Expected SheetFieldInUse');
            } catch (SheetFieldInUse $inUse) {
                self::assertSame($paths, $inUse->paths());
            }
        }

        $this->assertUntouched($gameSystem, $document);
    }

    #[Test]
    public function deletingAnUnknownFieldIsNotFound(): void
    {
        $this->expectException(SheetFieldNotFound::class);

        $this->draft()->deleteSheetField('ghost', $this->now);
    }

    #[Test]
    public function aFieldMovesToAnyPositionOfTheWholeList(): void
    {
        $gameSystem = $this->draft();

        $gameSystem->moveSheetField('bonus', 0, $this->now);
        self::assertSame(['bonus', 'pow', 'hp', 'sanity'], $this->keys($gameSystem));

        $gameSystem->moveSheetField('bonus', 3, $this->now);
        self::assertSame(['pow', 'hp', 'sanity', 'bonus'], $this->keys($gameSystem));

        $gameSystem->moveSheetField('pow', 2, $this->now);
        self::assertSame(['hp', 'sanity', 'pow', 'bonus'], $this->keys($gameSystem));
        self::assertEquals($this->now, $gameSystem->updatedAt());
    }

    #[Test]
    public function movingChangesNothingButTheOrder(): void
    {
        $gameSystem = $this->draft();
        $fields = $this->fields($gameSystem);

        $gameSystem->moveSheetField('pow', 3, $this->now);

        self::assertSame([$fields[1], $fields[2], $fields[3], $fields[0]], $this->fields($gameSystem));
    }

    #[Test]
    public function anIndexOutOfRangeIsRefusedAndNothingChanges(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();

        foreach ([-1, 4, 99] as $index) {
            try {
                $gameSystem->moveSheetField('pow', $index, $this->now);
                self::fail('Expected InvalidReleaseContent');
            } catch (InvalidReleaseContent $invalid) {
                self::assertSame(\sprintf('index: must be between 0 and 3, %d given', $index), $invalid->getMessage());
            }
        }

        $this->assertUntouched($gameSystem, $document);
    }

    #[Test]
    public function movingAnUnknownFieldIsNotFound(): void
    {
        $this->expectException(SheetFieldNotFound::class);

        $this->draft()->moveSheetField('ghost', 0, $this->now);
    }

    #[Test]
    public function aLegacyDraftBreakingTheItemRulesElsewhereIsRefusedWithThatPath(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->saveSheetField(null, self::field(), $this->now);
        $gameSystem->restore(ReleaseArrays::with($gameSystem->document(), 'sceneTypes.0.oracles', ['ghost']), $this->created);
        $document = $gameSystem->document();
        $version = $gameSystem->version();

        foreach ([
            fn () => $gameSystem->saveSheetField(null, self::field('mana'), $this->now),
            fn () => $gameSystem->deleteSheetField('luck', $this->now),
            fn () => $gameSystem->moveSheetField('hp', 0, $this->now),
        ] as $command) {
            try {
                $command();
                self::fail('Expected InvalidReleaseContent');
            } catch (InvalidReleaseContent $invalid) {
                self::assertStringStartsWith('sceneTypes[0].oracles[0]: ', $invalid->getMessage());
            }
        }

        self::assertSame($document, $gameSystem->document());
        self::assertSame($version, $gameSystem->version());
    }

    #[Test]
    public function aSheetOrFieldsThatAreNotWellFormedAreRefusedAtTheirPath(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->restore(ReleaseArrays::with($gameSystem->document(), 'sheet', ['fields' => 'many']), $this->created);
        try {
            $gameSystem->saveSheetField(null, self::field(), $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertSame('sheet.fields: must be a list', $invalid->getMessage());
        }

        $gameSystem->restore(ReleaseArrays::with($gameSystem->document(), 'sheet', ['a', 'b']), $this->created);
        try {
            $gameSystem->moveSheetField('a', 0, $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertSame('sheet: must be an object', $invalid->getMessage());
        }
    }

    #[Test]
    public function aSchemaVersion1DraftHasNoSheetCommands(): void
    {
        $gameSystem = $this->draft('valid/contract-doc-example');
        $document = $gameSystem->document();

        foreach ([
            fn () => $gameSystem->saveSheetField(null, self::field(), $this->now),
            fn () => $gameSystem->deleteSheetField('luck', $this->now),
            fn () => $gameSystem->moveSheetField('luck', 0, $this->now),
        ] as $command) {
            try {
                $command();
                self::fail('Expected InvalidReleaseContent');
            } catch (InvalidReleaseContent $invalid) {
                self::assertSame('schemaVersion: the sheet needs schema version 2, this draft is version 1', $invalid->getMessage());
            }
        }

        self::assertSame($document, $gameSystem->document());
    }

    /**
     * The dice pools release: its outcomes mark and clear "hurt", and here the sneak push marks it too.
     */
    private function conditionsDraft(): GameSystem
    {
        /** @var array{checks: list<array{push: array{effects: list<array<string, mixed>>}}>} $source */
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-dice-pools'))->toArray();
        $source['checks'][0]['push']['effects'][0] = ['kind' => 'sheet', 'field' => 'physical', 'op' => 'mark', 'value' => 'hurt'];

        return GameSystem::createFromRelease(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a72'), 'my-copy', 'My copy', null, 'user-1', $source, $this->created);
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldNamed(GameSystem $gameSystem, string $key): array
    {
        return array_find($this->fields($gameSystem), static fn (array $field): bool => $key === $field['key']) ?? self::fail('No field '.$key);
    }
}
