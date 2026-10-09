<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\FlowStep;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\SnapshotLikelihoodOracle;
use App\Play\Domain\GameSystem\UnknownGameSystemOracle;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Randomness\Domain\Oracle\LikelihoodOracle;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GameSystemReleaseTranslator::class)]
#[CoversClass(GameSystemSnapshot::class)]
#[CoversClass(SnapshotLikelihoodOracle::class)]
#[CoversClass(FlowStep::class)]
#[CoversClass(UnsupportedReleaseSchemaVersion::class)]
#[CoversClass(InvalidGameSystemRelease::class)]
#[CoversClass(UnknownGameSystemOracle::class)]
final class GameSystemReleaseTranslatorTest extends TestCase
{
    private GameSystemReleaseTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new GameSystemReleaseTranslator();
    }

    #[Test]
    public function itTranslatesASchemaVersion1ReleaseIntoASnapshot(): void
    {
        $snapshot = $this->translator->translate(ReleaseViews::of(ReleaseViews::contractDocExampleContent(), 3));

        self::assertSame('example-journal', $snapshot->gameSystemKey());
        self::assertSame('Example journal', $snapshot->name());
        self::assertSame(3, $snapshot->releaseVersion());
    }

    #[Test]
    public function itsOracleTablesResolveWithTheirNestedTables(): void
    {
        $snapshot = $this->translator->translate(ReleaseViews::of(ReleaseViews::contractDocExampleContent()));

        self::assertSame(['weather', 'storm-kind'], $snapshot->oracleTableKeys());
        self::assertTrue($snapshot->hasOracleTable('weather'));
        self::assertFalse($snapshot->hasOracleTable('fate'));

        $result = $snapshot->resolveOracleTable('weather', new ScriptedRandomNumberGenerator(5, 3));

        self::assertSame(['Storm', 'Hail'], array_map(static fn ($step): string => $step->text(), $result->steps()));
    }

    #[Test]
    public function itsLikelihoodOraclesAnswerByKey(): void
    {
        $snapshot = $this->translator->translate(ReleaseViews::of(ReleaseViews::contractDocExampleContent()));

        self::assertTrue($snapshot->hasLikelihoodOracle('fate'));
        self::assertFalse($snapshot->hasLikelihoodOracle('weather'));
        $fate = $snapshot->likelihoodOracle('fate');
        self::assertSame('fate', $fate->key());
        self::assertSame('Fate question', $fate->name());
        self::assertSame(['fate'], array_map(static fn (SnapshotLikelihoodOracle $oracle): string => $oracle->key(), $snapshot->likelihoodOracles()));

        $answer = $fate->oracle()->ask('likely', null, new ScriptedRandomNumberGenerator(40));

        self::assertTrue($answer->answer()->isYes());
        self::assertSame(5, $answer->chaosFactor());
    }

    #[Test]
    public function itsFlowStepsKeepTheirOrderAndOptionalPrompt(): void
    {
        $content = ReleaseViews::contractDocExampleContent();
        $content['flow'] = ['steps' => [
            ['key' => 'set-scene', 'title' => 'Set the scene', 'prompt' => 'Where are you?'],
            ['key' => 'act', 'title' => 'Act'],
        ]];

        $snapshot = $this->translator->translate(ReleaseViews::of($content));

        self::assertEquals(
            [new FlowStep('set-scene', 'Set the scene', 'Where are you?'), new FlowStep('act', 'Act', null)],
            $snapshot->flowSteps(),
        );
    }

    #[Test]
    public function aReleaseWithoutOraclesOrStepsHasAnEmptySnapshot(): void
    {
        $content = ReleaseViews::contractDocExampleContent();
        $content['oracles'] = ['tables' => [], 'likelihood' => []];
        $content['flow'] = ['steps' => []];

        $snapshot = $this->translator->translate(ReleaseViews::of($content));

        self::assertSame([], $snapshot->oracleTableKeys());
        self::assertFalse($snapshot->hasOracleTable('weather'));
        self::assertSame([], $snapshot->likelihoodOracles());
        self::assertFalse($snapshot->hasLikelihoodOracle('fate'));
        self::assertSame([], $snapshot->flowSteps());
    }

    #[Test]
    public function anUnknownOracleTableIsAPlayError(): void
    {
        $content = ReleaseViews::contractDocExampleContent();
        $content['oracles'] = ['tables' => [], 'likelihood' => []];
        $snapshot = $this->translator->translate(ReleaseViews::of($content));

        $this->expectException(UnknownGameSystemOracle::class);
        $this->expectExceptionMessageIsOrContains('GameSystem "example-journal" v1 has no oracle table "weather".');

        $snapshot->resolveOracleTable('weather', new ScriptedRandomNumberGenerator(1));
    }

    #[Test]
    public function anUnknownLikelihoodOracleIsAPlayError(): void
    {
        $snapshot = $this->translator->translate(ReleaseViews::of(ReleaseViews::contractDocExampleContent()));

        $this->expectException(UnknownGameSystemOracle::class);
        $this->expectExceptionMessageIsOrContains('GameSystem "example-journal" v1 has no likelihood oracle "weather".');

        $snapshot->likelihoodOracle('weather');
    }

    #[Test]
    public function anUnsupportedSchemaVersionIsRejected(): void
    {
        $this->expectException(UnsupportedReleaseSchemaVersion::class);
        $this->expectExceptionMessageIsOrContains('GameSystem "example-journal" v1 uses schema version 3; Play supports schema version(s) 1, 2.');

        $this->translator->translate(ReleaseViews::of(ReleaseViews::contractDocExampleContent(), schemaVersion: 3));
    }

    #[Test]
    public function aLikelihoodOracleIsBuiltFromItsDefinitionWithoutKeyAndName(): void
    {
        $definition = [
            'sides' => 6,
            'levels' => [['key' => 'even', 'label' => 'Even', 'target' => 3]],
        ];
        $content = ReleaseViews::contractDocExampleContent();
        $content['oracles'] = ['tables' => [], 'likelihood' => [['key' => 'coin', 'name' => 'Coin flip'] + $definition]];

        $coin = $this->translator->translate(ReleaseViews::of($content))->likelihoodOracle('coin');

        self::assertSame('coin', $coin->key());
        self::assertSame('Coin flip', $coin->name());
        self::assertEquals(LikelihoodOracle::fromArray($definition), $coin->oracle());
    }

    #[Test]
    public function aDefinitionRandomnessRejectsBecomesAPlayError(): void
    {
        $content = ReleaseViews::contractDocExampleContent();
        $content['oracles'] = ['tables' => [], 'likelihood' => [['key' => 'coin', 'name' => 'Coin flip', 'sides' => 1, 'levels' => []]]];

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('GameSystem "example-journal" v1 cannot be read by Play: oracles.likelihood[0]:');

        $this->translator->translate(ReleaseViews::of($content));
    }

    #[Test]
    public function malformedContentIsAPlayError(): void
    {
        $content = ReleaseViews::contractDocExampleContent();
        $content['flow'] = ['steps' => [['title' => 'No key']]];

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('GameSystem "example-journal" v1 cannot be read by Play: flow.steps[0].key: must be a string.');

        $this->translator->translate(ReleaseViews::of($content));
    }

    #[Test]
    public function aMalformedOracleTableIsAPlayError(): void
    {
        $content = ReleaseViews::contractDocExampleContent();
        $content['oracles'] = ['tables' => [[
            'key' => 'weather',
            'name' => 'Weather',
            'dice' => '1d6',
            'entries' => [['min' => 1, 'max' => 4, 'text' => 'Clear'], ['min' => 3, 'max' => 6, 'text' => 'Storm']],
        ]], 'likelihood' => []];

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('GameSystem "example-journal" v1 cannot be read by Play: oracles.tables: ');

        $this->translator->translate(ReleaseViews::of($content));
    }

    /**
     * @param array<string, mixed> $content
     */
    #[Test]
    #[DataProvider('wronglyTypedContainers')]
    public function aWronglyTypedContainerIsAPlayError(array $content, string $expectedMessage): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('GameSystem "example-journal" v1 cannot be read by Play: '.$expectedMessage);

        $this->translator->translate(ReleaseViews::of($content));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function wronglyTypedContainers(): iterable
    {
        $content = ReleaseViews::contractDocExampleContent();

        yield 'tables as an object' => [['oracles' => ['tables' => ['weather' => []], 'likelihood' => []]] + $content, 'oracles.tables: must be a list.'];
        yield 'tables as a \stdClass' => [['oracles' => ['tables' => new \stdClass(), 'likelihood' => []]] + $content, 'oracles.tables: must be a list.'];
        yield 'likelihood as a scalar' => [['oracles' => ['tables' => [], 'likelihood' => 'fate']] + $content, 'oracles.likelihood: must be a list.'];
        yield 'steps as an object' => [['flow' => ['steps' => ['set-scene' => ['key' => 'set-scene', 'title' => 'Set the scene']]]] + $content, 'flow.steps: must be a list.'];
        yield 'a step as a scalar' => [['flow' => ['steps' => ['set-scene']]] + $content, 'flow.steps[0]: must be an object.'];
        yield 'a step as a list' => [['flow' => ['steps' => [['set-scene', 'Set the scene']]]] + $content, 'flow.steps[0]: must be an object.'];
    }

    #[Test]
    public function aDuplicateLikelihoodOracleKeyIsAPlayError(): void
    {
        $oracle = ['key' => 'coin', 'name' => 'Coin flip', 'sides' => 6, 'levels' => [['key' => 'even', 'label' => 'Even', 'target' => 3]]];
        $content = ReleaseViews::contractDocExampleContent();
        $content['oracles'] = ['tables' => [], 'likelihood' => [$oracle, ['name' => 'Coin flip again'] + $oracle]];

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('GameSystem "example-journal" v1 cannot be read by Play: oracles.likelihood[1].key: duplicate key "coin".');

        $this->translator->translate(ReleaseViews::of($content));
    }

    #[Test]
    public function aDuplicateFlowStepKeyIsAPlayError(): void
    {
        $content = ReleaseViews::contractDocExampleContent();
        $content['flow'] = ['steps' => [['key' => 'act', 'title' => 'Act'], ['key' => 'set-scene', 'title' => 'Set the scene'], ['key' => 'act', 'title' => 'Act again']]];

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('GameSystem "example-journal" v1 cannot be read by Play: flow.steps[2].key: duplicate key "act".');

        $this->translator->translate(ReleaseViews::of($content));
    }
}
