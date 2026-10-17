<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\GameSystem;

use App\Studio\Domain\GameSystem\CheckInUse;
use App\Studio\Domain\GameSystem\CheckKeyTaken;
use App\Studio\Domain\GameSystem\CheckNotFound;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs against valid/v2-checks: the check "skill" (difficulty 9..29, bands fail, success, crit) is
 * named by the check step sceneTypes[0].play[0], whose outcomes name the bands fail and crit; the
 * check "luck" (bands bad, ok, good) is named by nothing.
 */
#[CoversClass(GameSystem::class)]
#[CoversClass(CheckInUse::class)]
#[CoversClass(CheckKeyTaken::class)]
#[CoversClass(CheckNotFound::class)]
final class GameSystemCheckTest extends TestCase
{
    private \DateTimeImmutable $created;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->created = new \DateTimeImmutable('2026-10-15T10:00:00');
        $this->now = new \DateTimeImmutable('2026-10-17T10:00:00');
    }

    private function draft(string $fixture = 'valid/v2-checks'): GameSystem
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture($fixture))->toArray();

        return GameSystem::createFromRelease(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a72'), 'my-copy', 'My copy', null, 'user-1', $source, $this->created);
    }

    /**
     * @return list<string>
     */
    private function keys(GameSystem $gameSystem): array
    {
        /** @var list<array{key: string}> $checks */
        $checks = $gameSystem->document()['checks'] ?? [];

        return array_column($checks, 'key');
    }

    /**
     * @return array<string, mixed>
     */
    private function check(GameSystem $gameSystem, int $index): array
    {
        /** @var list<array<string, mixed>> $checks */
        $checks = $gameSystem->document()['checks'] ?? [];

        return $checks[$index];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array<string, mixed>> the steps of the play part of the first Scene Type
     */
    private function steps(array $document): array
    {
        /** @var list<array{play: list<array<string, mixed>>}> $sceneTypes */
        $sceneTypes = $document['sceneTypes'];

        return $sceneTypes[0]['play'];
    }

    /**
     * @param array<string, mixed> $check
     *
     * @return list<array<string, mixed>>
     */
    private function bands(array $check): array
    {
        /** @var list<array<string, mixed>> $bands */
        $bands = $check['bands'];

        return $bands;
    }

    /**
     * @return array<string, mixed>
     */
    private static function newCheck(string $key = 'grit'): array
    {
        return ['key' => $key, 'label' => 'Grit', 'dice' => '2d6', 'bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => 7], ['key' => 'hit', 'label' => 'Hit']]];
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
    public function aNewCheckIsAppendedAsGivenAndUpdatesTheDraft(): void
    {
        $gameSystem = $this->draft();

        $gameSystem->saveCheck(null, self::newCheck(), $this->now);

        self::assertSame(['skill', 'luck', 'grit'], $this->keys($gameSystem));
        self::assertSame(self::newCheck(), $this->check($gameSystem, 2));
        self::assertEquals($this->now, $gameSystem->updatedAt());
        ReleaseContent::checkItemRules($gameSystem->document());
    }

    #[Test]
    public function aReplacementWithTheSameKeyChangesOnlyThatCheckAndKeepsItsPosition(): void
    {
        $gameSystem = $this->draft();
        $luck = $this->check($gameSystem, 1);

        $gameSystem->saveCheck('skill', [...$this->check($gameSystem, 0), 'label' => 'Skill roll', 'dice' => '2d10'], $this->now);

        self::assertSame(['skill', 'luck'], $this->keys($gameSystem));
        self::assertSame(['Skill roll', '2d10'], [$this->check($gameSystem, 0)['label'], $this->check($gameSystem, 0)['dice']]);
        self::assertSame($luck, $this->check($gameSystem, 1));
    }

    #[Test]
    public function anUnreferencedCheckIsRenamedInPlace(): void
    {
        $gameSystem = $this->draft();

        $gameSystem->saveCheck('luck', [...$this->check($gameSystem, 1), 'key' => 'fortune'], $this->now);

        self::assertSame(['skill', 'fortune'], $this->keys($gameSystem));
    }

    #[Test]
    public function aReferencedCheckCannotBeRenamedAndTheRefusalListsThePaths(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();
        /** @var array<string, mixed> $document */
        $document = ReleaseArrays::with($document, 'sceneTypes.0.closing', [['kind' => 'check', 'check' => 'skill']]);
        $gameSystem->restore($document, $this->created);

        try {
            $gameSystem->saveCheck('skill', [...$this->check($gameSystem, 0), 'key' => 'aptitude'], $this->now);
            self::fail('Expected CheckInUse');
        } catch (CheckInUse $inUse) {
            self::assertSame(['sceneTypes[0].play[0].check', 'sceneTypes[0].closing[0].check'], $inUse->paths());
            self::assertSame('The check "skill" is used at sceneTypes[0].play[0].check, sceneTypes[0].closing[0].check.', $inUse->getMessage());
        }

        $this->assertUntouched($gameSystem, $document);
    }

    #[Test]
    public function aKeyOfAnotherCheckIsTaken(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();

        foreach ([[null, 'luck'], ['skill', 'luck']] as [$original, $key]) {
            try {
                $gameSystem->saveCheck($original, self::newCheck($key), $this->now);
                self::fail('Expected CheckKeyTaken');
            } catch (CheckKeyTaken $taken) {
                self::assertSame('The draft already has a check "luck".', $taken->getMessage());
            }
        }

        $this->assertUntouched($gameSystem, $document);
    }

    #[Test]
    public function anUnknownKeyIsNotFoundByEveryCommand(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();

        foreach ([
            fn () => $gameSystem->saveCheck('ghost', self::newCheck(), $this->now),
            fn () => $gameSystem->deleteCheck('ghost', $this->now),
            fn () => $gameSystem->moveCheck('ghost', 0, $this->now),
        ] as $command) {
            try {
                $command();
                self::fail('Expected CheckNotFound');
            } catch (CheckNotFound $notFound) {
                self::assertSame('The draft has no check "ghost".', $notFound->getMessage());
            }
        }

        $this->assertUntouched($gameSystem, $document);
    }

    #[Test]
    public function aReplacementOfAReferencedCheckWithoutAStringKeyFailsOnTheKeyPath(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();
        $skill = $this->check($gameSystem, 0);
        unset($skill['key']);

        foreach ([$skill, [...$skill, 'key' => 7], [...$skill, 'key' => ['skill']]] as $replacement) {
            try {
                $gameSystem->saveCheck('skill', $replacement, $this->now);
                self::fail('Expected InvalidReleaseContent');
            } catch (InvalidReleaseContent $invalid) {
                self::assertStringStartsWith('checks[0].key:', $invalid->getMessage());
            }
        }

        $this->assertUntouched($gameSystem, $document);
    }

    #[Test]
    public function aBandRenameWhileAnOutcomeNamesItIsRefusedWithTheOutcomePaths(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();
        $skill = ReleaseArrays::with($this->check($gameSystem, 0), 'bands.0.key', 'failed');

        try {
            $gameSystem->saveCheck('skill', $skill, $this->now);
            self::fail('Expected CheckInUse');
        } catch (CheckInUse $inUse) {
            self::assertSame(['sceneTypes[0].play[0].outcomes[0].band'], $inUse->paths());
            self::assertSame('A band of the check "skill" is used at sceneTypes[0].play[0].outcomes[0].band.', $inUse->getMessage());
        }

        $this->assertUntouched($gameSystem, $document);
    }

    #[Test]
    public function droppingAReferencedBandListsEveryOutcomeThatNamesIt(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();
        /** @var array<string, mixed> $document */
        $document = ReleaseArrays::with($document, 'sceneTypes.0.closing', [['kind' => 'check', 'check' => 'skill', 'outcomes' => [['band' => 'crit']]]]);
        $gameSystem->restore($document, $this->created);
        $bands = $this->bands($this->check($gameSystem, 0));
        $skill = [...$this->check($gameSystem, 0), 'bands' => [[...$bands[0], 'upTo' => 5], [...$bands[2], 'key' => 'great']]];

        try {
            $gameSystem->saveCheck('skill', $skill, $this->now);
            self::fail('Expected CheckInUse');
        } catch (CheckInUse $inUse) {
            self::assertSame(['sceneTypes[0].play[0].outcomes[1].band', 'sceneTypes[0].closing[0].outcomes[0].band'], $inUse->paths());
        }

        $this->assertUntouched($gameSystem, $document);
    }

    #[Test]
    public function aBandNoOutcomeNamesMayBeRemovedOrRenamed(): void
    {
        $gameSystem = $this->draft();
        $bands = $this->bands($this->check($gameSystem, 0));
        $skill = [...$this->check($gameSystem, 0), 'bands' => [$bands[0], $bands[2]]];

        $gameSystem->saveCheck('skill', $skill, $this->now);

        self::assertSame(['fail', 'crit'], array_column($this->bands($this->check($gameSystem, 0)), 'key'));

        $luck = ReleaseArrays::with($this->check($gameSystem, 1), 'bands.0.key', 'awful');
        $gameSystem->saveCheck('luck', $luck, $this->now);

        self::assertSame(['awful', 'ok', 'good'], array_column($this->bands($this->check($gameSystem, 1)), 'key'));
    }

    #[Test]
    public function aDifficultyThatAStepSetsCannotBeRemovedAndTheItemRulesNameTheStep(): void
    {
        $gameSystem = $this->draft();
        $luck = [...$this->check($gameSystem, 1), 'difficulty' => ['label' => 'Difficulty', 'default' => 10]];
        $gameSystem->saveCheck('luck', $luck, $this->now);
        $document = $gameSystem->document();
        $play = $this->steps($document);
        /** @var array<string, mixed> $document */
        $document = ReleaseArrays::with($document, 'sceneTypes.0.play', [...$play, ['key' => 'try-luck', 'kind' => 'check', 'title' => 'Try your luck', 'check' => 'luck', 'difficulty' => 12]]);
        $gameSystem->restore($document, $this->created);
        unset($luck['difficulty']);

        try {
            $gameSystem->saveCheck('luck', $luck, $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith('sceneTypes[0].play[2].difficulty: check "luck" declares no difficulty', $invalid->getMessage());
        }

        self::assertSame($document, $gameSystem->document());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidChecks(): iterable
    {
        yield 'a bad key' => [[...self::newCheck(), 'key' => 'Grit!'], 'checks[2].key'];
        yield 'dice the kernel refuses' => [[...self::newCheck(), 'dice' => '2d'], 'checks[2].dice'];
        yield 'a modifier over an unknown field' => [[...self::newCheck(), 'modifier' => '{ghost}'], 'checks[2].modifier'];
        yield 'no bands' => [[...self::newCheck(), 'bands' => []], 'checks[2].bands'];
        yield 'a last band with a bound' => [[...self::newCheck(), 'bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => 7]]], 'checks[2].bands[0]'];
        yield 'a difficulty read without one' => [[...self::newCheck(), 'bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => ['formula' => '{check:difficulty}']], ['key' => 'hit', 'label' => 'Hit']]], 'checks[2].bands[0].upTo.formula'];
    }

    /**
     * @param array<string, mixed> $check
     * @param non-empty-string     $path
     */
    #[Test]
    #[DataProvider('invalidChecks')]
    public function anInvalidCheckIsRefusedWithItsPathAndNothingChanges(array $check, string $path): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();

        try {
            $gameSystem->saveCheck(null, $check, $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith($path, $invalid->getMessage());
        }

        $this->assertUntouched($gameSystem, $document);
    }

    #[Test]
    public function anUnreferencedCheckIsDeleted(): void
    {
        $gameSystem = $this->draft();

        $gameSystem->deleteCheck('luck', $this->now);

        self::assertSame(['skill'], $this->keys($gameSystem));
        self::assertEquals($this->now, $gameSystem->updatedAt());
    }

    #[Test]
    public function theLastCheckCanBeDeleted(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();
        $document['sceneTypes'] = [];
        $gameSystem->restore($document, $this->created);

        $gameSystem->deleteCheck('skill', $this->now);
        $gameSystem->deleteCheck('luck', $this->now);

        self::assertSame([], $gameSystem->document()['checks']);
    }

    #[Test]
    public function aReferencedCheckCannotBeDeleted(): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();

        try {
            $gameSystem->deleteCheck('skill', $this->now);
            self::fail('Expected CheckInUse');
        } catch (CheckInUse $inUse) {
            self::assertSame(['sceneTypes[0].play[0].check'], $inUse->paths());
        }

        $this->assertUntouched($gameSystem, $document);
    }

    #[Test]
    public function aCheckMovesToAPositionOfTheWholeList(): void
    {
        $gameSystem = $this->draft();
        $gameSystem->saveCheck(null, self::newCheck(), $this->now);
        $before = $gameSystem->document();

        $gameSystem->moveCheck('grit', 0, $this->now);
        self::assertSame(['grit', 'skill', 'luck'], $this->keys($gameSystem));

        $gameSystem->moveCheck('grit', 2, $this->now);
        self::assertSame(['skill', 'luck', 'grit'], $this->keys($gameSystem));
        self::assertSame($before, $gameSystem->document());

        $gameSystem->moveCheck('luck', 1, $this->now);
        self::assertSame($before, $gameSystem->document());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function outOfRangeIndexes(): iterable
    {
        yield 'negative' => [-1];
        yield 'one past the end' => [2];
    }

    #[Test]
    #[DataProvider('outOfRangeIndexes')]
    public function anIndexOutOfRangeIsRefusedOnIndexAndNothingChanges(int $index): void
    {
        $gameSystem = $this->draft();
        $document = $gameSystem->document();

        try {
            $gameSystem->moveCheck('skill', $index, $this->now);
            self::fail('Expected InvalidReleaseContent');
        } catch (InvalidReleaseContent $invalid) {
            self::assertStringStartsWith(\sprintf('index: must be between 0 and 1, %d given', $index), $invalid->getMessage());
        }

        $this->assertUntouched($gameSystem, $document);
    }

    #[Test]
    public function aDraftWithoutChecksIsTreatedAsHavingNone(): void
    {
        $gameSystem = $this->draft('valid/v2-minimal');
        $document = $gameSystem->document();
        unset($document['checks']);
        $gameSystem->restore($document, $this->created);

        foreach ([
            fn () => $gameSystem->deleteCheck('grit', $this->now),
            fn () => $gameSystem->moveCheck('grit', 0, $this->now),
        ] as $command) {
            try {
                $command();
                self::fail('Expected CheckNotFound');
            } catch (CheckNotFound) {
            }
        }

        self::assertSame($document, $gameSystem->document());

        $gameSystem->saveCheck(null, self::newCheck(), $this->now);

        self::assertSame(['grit'], $this->keys($gameSystem));
    }

    #[Test]
    public function checksThatAreNotAListAreRefusedOnChecksAndNothingChanges(): void
    {
        $gameSystem = $this->draft();
        $document = [...$gameSystem->document(), 'checks' => 'nope'];
        $gameSystem->restore($document, $this->created);

        foreach ([
            fn () => $gameSystem->saveCheck(null, self::newCheck(), $this->now),
            fn () => $gameSystem->deleteCheck('skill', $this->now),
            fn () => $gameSystem->moveCheck('skill', 0, $this->now),
        ] as $command) {
            try {
                $command();
                self::fail('Expected InvalidReleaseContent');
            } catch (InvalidReleaseContent $invalid) {
                self::assertStringStartsWith('checks: must be a list', $invalid->getMessage());
            }
        }

        self::assertSame($document, $gameSystem->document());
    }

    #[Test]
    public function aSchemaVersion1DraftRefusesEveryCheckCommand(): void
    {
        $gameSystem = $this->draft('valid/contract-doc-example');
        $document = $gameSystem->document();

        foreach ([
            fn () => $gameSystem->saveCheck(null, self::newCheck(), $this->now),
            fn () => $gameSystem->deleteCheck('grit', $this->now),
            fn () => $gameSystem->moveCheck('grit', 0, $this->now),
        ] as $command) {
            try {
                $command();
                self::fail('Expected InvalidReleaseContent');
            } catch (InvalidReleaseContent $invalid) {
                self::assertStringStartsWith('schemaVersion: checks need schema version 2', $invalid->getMessage());
            }
        }

        $this->assertUntouched($gameSystem, $document);
    }
}
