<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseFields;
use App\Studio\Domain\Release\ReleaseOracles;
use App\Studio\Domain\Release\Version2\Bands;
use App\Studio\Domain\Release\Version2\Catalog;
use App\Studio\Domain\Release\Version2\Effects;
use App\Studio\Domain\Release\Version2\FlowReferences;
use App\Studio\Domain\Release\Version2\Placeholders;
use App\Studio\Domain\Release\Version2\ReleaseVersion2;
use App\Studio\Domain\Release\Version2\StepParts;
use App\Studio\Domain\Release\Version2\Steps;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Schema version 2 rules, each against the fixture valid/v2-catalog.json. Its indexes: tables
 * events (0: patrol, lucky-break, run) and escape-routes (1); likelihood oracles fate (0, chaos
 * bound to "chaos") and omen (1); trackers alarm (0, clock), edge (1, counter with levels), chaos
 * (2, counter 1..9) and heat (3); fact slots target (0), client (1) and ambition (2).
 *
 * Scene Type rules run against valid/v2-scene-types.json: tables complications (0: patrol, spotted,
 * run) and escape-routes (1); trackers alarm (0, clock), edge (1), chaos (2) and heat (3); Scene
 * Types legwork (0: setup goal, closing choice gain-edge), infiltration (1: play oracle slip-past,
 * table complication), firefight (2: setup who-shoots) and getaway (3: setup how, skipped, route,
 * chase; play ask, pick; closing conditions cool and lockdown).
 *
 * Flow rules run against valid/v2-contract-doc-example.json: tables heist-scenes (0) and
 * complications (1: patrol, lucky-break, spotted); trackers alarm, edge, chaos; Scene Types legwork
 * (0), infiltration (1), firefight (2); flow heist with phases legwork (0, player), the-heist (1,
 * oracle) and getaway (2, sequence).
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(ReleaseFields::class)]
#[CoversClass(ReleaseOracles::class)]
#[CoversClass(ReleaseVersion2::class)]
#[CoversClass(Bands::class)]
#[CoversClass(Catalog::class)]
#[CoversClass(Steps::class)]
#[CoversClass(StepParts::class)]
#[CoversClass(Effects::class)]
#[CoversClass(FlowReferences::class)]
#[CoversClass(Placeholders::class)]
final class ReleaseContentVersion2Test extends TestCase
{
    /**
     * @return array<mixed>
     */
    private static function release(): array
    {
        return ReleaseArrays::fixture('valid/v2-catalog');
    }

    /**
     * @param array<mixed> $release
     *
     * @return array<mixed>
     */
    private static function set(string $path, mixed $value, ?array $release = null): array
    {
        return ReleaseArrays::with($release ?? self::release(), $path, $value);
    }

    /**
     * @param array<mixed> $release
     *
     * @return array<mixed>
     */
    private static function unset(string $path, ?array $release = null): array
    {
        return ReleaseArrays::without($release ?? self::release(), $path);
    }

    /**
     * valid/v2-scene-types.json, with $value at $path when given.
     *
     * @return array<mixed>
     */
    private static function scenes(?string $path = null, mixed $value = null): array
    {
        $release = ReleaseArrays::fixture('valid/v2-scene-types');

        return null === $path ? $release : ReleaseArrays::with($release, $path, $value);
    }

    /**
     * valid/v2-contract-doc-example.json (or $release), with $value at $path when given.
     *
     * @param array<mixed> $release
     *
     * @return array<mixed>
     */
    private static function example(?string $path = null, mixed $value = null, ?array $release = null): array
    {
        $release ??= ReleaseArrays::fixture('valid/v2-contract-doc-example');

        return null === $path ? $release : ReleaseArrays::with($release, $path, $value);
    }

    /**
     * The example plus a "noise" clock no flow uses and a "dawn" Scene Type no flow reaches.
     *
     * @return array<mixed>
     */
    private static function withNoiseAndDawn(): array
    {
        return self::example('sceneTypes.3', [
            'key' => 'dawn', 'name' => 'Dawn', 'purpose' => 'The night ends.', 'oracles' => ['heist-scenes'],
            'setup' => [], 'play' => [['key' => 'sunrise', 'kind' => 'prompt', 'title' => 'Noise at {tracker:noise}', 'effects' => [['kind' => 'tracker', 'tracker' => 'noise', 'op' => 'add', 'value' => 1]]]],
            'closing' => [],
        ], self::example('trackers.3', ['key' => 'noise', 'name' => 'Noise', 'kind' => 'clock', 'segments' => 4]));
    }

    /**
     * A one-phase flow on the legwork Scene Type.
     *
     * @return array<string, mixed>
     */
    private static function flowSummary(string $key): array
    {
        return [
            'key' => $key, 'name' => 'Quick', 'defaultView' => 'journal', 'oracles' => ['fate'], 'trackers' => ['edge'],
            'phases' => [['key' => 'only', 'name' => 'Only', 'mode' => 'once', 'selection' => ['rule' => 'player', 'sceneTypes' => ['legwork']]]],
        ];
    }

    #[Test]
    public function itAcceptsTheCatalog(): void
    {
        $content = ReleaseContent::fromArray(self::release());

        self::assertSame(2, $content->schemaVersion());
        self::assertSame('catalog', $content->gameSystemKey());
        self::assertSame(['schemaVersion', 'gameSystem', 'oracles', 'trackers', 'factSlots', 'sceneTypes', 'flows', 'sheet', 'checks'], array_keys($content->toArray()));
        self::assertSame(self::release()['trackers'], $content->toArray()['trackers']);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function validVariants(): iterable
    {
        yield 'an entry key repeated in another table' => [self::set('oracles.tables.1.entries.2.key', 'patrol')];
        yield 'a counter with min equal to max' => [self::set('trackers.1', ['key' => 'edge', 'name' => 'Edge', 'kind' => 'counter', 'min' => 2, 'max' => 2, 'initial' => 2])];
        yield 'a single level catching every value' => [self::set('trackers.1.levels', [['label' => 'Any']])];
        yield 'counters and clocks sharing no namespace with oracles' => [self::set('trackers.0.key', 'fate')];
        yield 'empty entry effects' => [self::set('oracles.tables.0.entries.0.effects', [])];
        yield 'integer-valued floats for tracker numbers and level upTo' => [self::set('trackers.3', ['key' => 'heat', 'name' => 'Heat', 'kind' => 'counter', 'min' => -5.0, 'max' => 5.0, 'initial' => 0.0, 'levels' => [['upTo' => 2.0, 'label' => 'Warm'], ['label' => 'Hot']]], self::set('trackers.0.segments', 6.0))];
        yield 'an integer-valued float chaos range bound to a counter' => [self::set('oracles.likelihood.0.chaos.min', 1.0, self::set('oracles.likelihood.0.chaos.max', 9.0))];

        // Scene Types and steps.
        yield 'a step key repeated in another step list' => [self::scenes('sceneTypes.2.setup.0.key', 'goal')];
        yield 'a mandatory choice without skip' => [self::unset('sceneTypes.0.closing.0.skip', self::scenes('sceneTypes.0.closing.0.mandatory', true))];
        yield 'a choice skipping to another option' => [self::scenes('sceneTypes.0.closing.0.skip', 'yes')];
        yield 'tracker bands mixed with literals' => [self::scenes('sceneTypes.3.closing.0.bands', [['upTo' => 2], ['upTo' => ['tracker' => 'edge']], ['upTo' => 4], []])];
        yield 'a tracker effect valued by a tracker' => [self::scenes('sceneTypes.2.setup.0.effects.0.value', ['tracker' => 'edge'])];
        yield 'next naming end' => [self::scenes('sceneTypes.3.play.1.next', 'end')];
        yield 'a Scene Type without steps' => [self::scenes('sceneTypes.2.setup', [])];
        yield 'a band upTo of an integer-valued float' => [self::scenes('sceneTypes.3.closing.1.bands.0.upTo', 5.0)];
        yield 'a roll step without bands' => [self::unset('flows.0.phases.0.worldTurn.0.bands', self::example())];

        // Flows, references inside a flow and placeholders.
        yield 'a sequence repeating a Scene Type' => [self::example('flows.0.phases.2.selection.sceneTypes', ['firefight', 'firefight'])];
        yield 'a hook band bounded by a flow tracker' => [self::example('flows.0.phases.1.sceneOpening.0.bands.0.upTo', ['tracker' => 'edge'])];
        yield 'a second flow that is not the default' => [self::example('flows.1', [...self::flowSummary('quick'), 'default' => false])];
        yield 'an oracle selection table with nested entries' => [self::example('oracles.tables.0.entries.1.table', 'complications')];
        yield 'an unreached Scene Type using trackers and oracles outside the flow' => [self::withNoiseAndDawn()];
        yield 'braces that are not placeholders' => [self::example('flows.0.phases.1.sceneOpening.1.prompt', 'A {Locked} door, {a b} and {}')];
        yield 'a step placeholder naming a step of a reachable Scene Type' => [self::example('flows.0.phases.0.sessionOpening.0.title', 'After {step:who-shoots}')];
        yield 'a tracker placeholder in effect text' => [self::example('sceneTypes.2.setup.0.effects.1.title', 'Alarm {tracker:alarm}')];
        yield 'placeholders outside any flow naming the release' => [self::example('flows', [], self::scenes('sceneTypes.0.setup.0.prompt', '{tracker:heat} after {step:chase}'))];
    }

    /**
     * @param array<mixed> $release
     */
    #[Test]
    #[DataProvider('validVariants')]
    public function itAcceptsValidVariants(array $release): void
    {
        self::assertSame(2, ReleaseContent::fromArray($release)->schemaVersion());
    }

    #[Test]
    public function itOmitsEmptyOptionalLists(): void
    {
        $empty = self::set('trackers.2.levels', [], self::set('oracles.tables.0.entries.0.effects', []));

        self::assertSame(json_encode(ReleaseContent::fromArray(self::release())->toArray()), json_encode(ReleaseContent::fromArray($empty)->toArray()));
    }

    #[Test]
    public function itNormalizesIntegerValuedFloats(): void
    {
        $content = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-integer-valued-floats'));
        $json = json_encode($content->toArray(), \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"kind":"counter","min":1,"max":9,"initial":5,"levels":[{"upTo":3,', $json);
        self::assertStringContainsString('"bands":[{"upTo":3,"effects":[{"kind":"tracker","tracker":"chaos","op":"add","value":-1}]},{}]', $json);
        self::assertStringNotContainsString('.0', $json);
    }

    #[Test]
    public function itOmitsEmptyOutcomesFalseFlagsAndEmptyLists(): void
    {
        $release = self::scenes();
        foreach ([
            'sceneTypes.0.setup.0.effects' => [], 'sceneTypes.2.setup.0.mandatory' => false, 'sceneTypes.2.setup.0.tip' => null,
            'sceneTypes.3.closing.1.bands.1.effects' => [], 'sceneTypes.1.play.0.branches.yes' => [], 'sceneTypes.1.play.1.otherwise.effects' => [],
            'oracles.tables.0.entries.1.effects' => [], 'sceneTypes.0.closing.0.options.1.effects' => [], 'sceneTypes.3.setup.2.branches.0.effects' => [],
        ] as $path => $value) {
            $release = ReleaseArrays::with($release, $path, $value);
        }

        self::assertSame(ReleaseContent::fromArray(self::scenes())->hash(), ReleaseContent::fromArray($release)->hash());
        self::assertSame(
            ReleaseContent::fromArray(self::unset('sceneTypes.1.play.0.branches', self::scenes()))->hash(),
            ReleaseContent::fromArray(self::scenes('sceneTypes.1.play.0.branches', ['yes' => null, 'no' => []]))->hash(),
        );
    }

    #[Test]
    public function itKeepsAnEmptyBandAsAnObject(): void
    {
        $json = json_encode(ReleaseContent::fromArray(self::scenes())->toArray(), \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"bands":[{"upTo":5},{}]', $json);
    }

    #[Test]
    public function itKeepsTheSceneTypesInCanonicalForm(): void
    {
        $content = ReleaseContent::fromArray(ReleaseArrays::reverseKeys(self::scenes()));

        /** @var list<array<string, mixed>> $sceneTypes */
        $sceneTypes = $content->toArray()['sceneTypes'];
        /** @var list<array<string, mixed>> $getawaySetup */
        $getawaySetup = $sceneTypes[3]['setup'];

        self::assertSame(ReleaseContent::fromArray(self::scenes())->hash(), $content->hash());
        self::assertSame(['key', 'name', 'purpose', 'tips', 'oracles', 'setup', 'play', 'closing'], array_keys($sceneTypes[2]));
        self::assertSame(['key', 'kind', 'title', 'prompt', 'tip', 'mandatory', 'next', 'effects'], array_keys($getawaySetup[0]));
    }

    #[Test]
    public function itHashesTheCanonicalContentWhateverTheKeyOrder(): void
    {
        $content = ReleaseContent::fromArray(self::release());
        $reordered = ReleaseContent::fromArray(ReleaseArrays::reverseKeys(self::release()));

        self::assertSame($content->hash(), $reordered->hash());
        self::assertSame(json_encode($content->toArray()), json_encode($reordered->toArray()));
        self::assertSame(hash('sha256', json_encode($content->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)), $content->hash());
    }

    #[Test]
    public function itOmitsEmptyHooksAndAFalseDefault(): void
    {
        $release = self::example('flows.0.phases.2.worldTurn', [], self::example('flows.0.phases.0.act'));
        $release = self::example('flows.1', [...self::flowSummary('quick'), 'default' => false], $release);
        $expected = self::example('flows.1', self::flowSummary('quick'), self::unset('flows.0.phases.0.act', self::example()));

        self::assertSame(ReleaseContent::fromArray($expected)->hash(), ReleaseContent::fromArray($release)->hash());
        self::assertSame(['key', 'name', 'act', 'mode', 'selection', 'sessionOpening', 'worldTurn'], array_keys($this->canonicalPhase(self::example(), 0)));
    }

    #[Test]
    public function itKeepsTheFlowsInCanonicalForm(): void
    {
        $content = ReleaseContent::fromArray(ReleaseArrays::reverseKeys(self::example()));

        /** @var list<array<string, mixed>> $flows */
        $flows = $content->toArray()['flows'];

        self::assertSame(ReleaseContent::fromArray(self::example())->hash(), $content->hash());
        self::assertSame(['key', 'name', 'description', 'introduction', 'default', 'defaultView', 'oracles', 'trackers', 'phases'], array_keys($flows[0]));
        self::assertSame(['key', 'name', 'act', 'mode', 'selection', 'sceneOpening', 'sceneClosing', 'worldTurn'], array_keys($this->canonicalPhase(self::example(), 1)));
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function canonicalRoundTrips(): iterable
    {
        yield 'version 2 with flows, empty bands, oracle branches and an empty sheet' => [ReleaseArrays::fixture('valid/v2-every-part')];
        yield 'version 2 Scene Types' => [self::scenes()];
        yield 'version 1' => [ReleaseArrays::fixture('valid/contract-doc-example')];
    }

    /**
     * toArray() keeps empty objects (bands, sheet) as \stdClass; fromArray() takes them back.
     *
     * @param array<mixed> $release
     */
    #[Test]
    #[DataProvider('canonicalRoundTrips')]
    public function itAcceptsItsOwnCanonicalArrayAndJson(array $release): void
    {
        $content = ReleaseContent::fromArray($release);
        $again = ReleaseContent::fromArray($content->toArray());
        /** @var array<mixed> $decoded */
        $decoded = json_decode(json_encode($content->toArray(), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(json_encode($content->toArray()), json_encode($again->toArray()));
        self::assertSame($content->hash(), $again->hash());
        self::assertSame($content->hash(), ReleaseContent::fromArray($decoded)->hash());
    }

    /**
     * @param array<mixed> $release
     *
     * @return array<string, mixed>
     */
    private function canonicalPhase(array $release, int $index): array
    {
        /** @var list<array{phases: list<array<string, mixed>>}> $flows */
        $flows = ReleaseContent::fromArray($release)->toArray()['flows'];

        return $flows[0]['phases'][$index];
    }

    /**
     * @return iterable<string, array{array<mixed>, non-empty-string}>
     */
    public static function invalidReleases(): iterable
    {
        $tracker = static fn (array $tracker): array => self::set('trackers.1', $tracker);
        $counter = ['key' => 'edge', 'name' => 'Edge', 'kind' => 'counter', 'min' => 0, 'max' => 3, 'initial' => 0];

        // Top level.
        yield 'unsupported schema version' => [self::set('schemaVersion', 3), 'schemaVersion: unsupported schema version 3, expected 1 or 2'];
        yield 'the flow of version 1' => [self::set('flow', ['steps' => []]), 'flow: unknown property'];
        yield 'missing trackers' => [self::unset('trackers'), 'trackers: required'];
        yield 'missing fact slots' => [self::unset('factSlots'), 'factSlots: required'];
        yield 'missing Scene Types' => [self::unset('sceneTypes'), 'sceneTypes: required'];
        yield 'missing flows' => [self::unset('flows'), 'flows: required'];
        yield 'Scene Types not a list' => [self::set('sceneTypes', 'legwork'), 'sceneTypes: must be a list'];
        yield 'non-empty sheet' => [self::set('sheet', ['hp' => 1]), 'sheet: not supported in schema version 2'];
        yield 'non-empty checks' => [self::set('checks', [1]), 'checks: not supported in schema version 2'];

        // Oracle table entries and chaos.
        yield 'bad entry key' => [self::set('oracles.tables.0.entries.0.key', 'Patrol'), 'oracles.tables[0].entries[0].key: must be 1 to 64 characters'];
        yield 'duplicate entry key' => [self::set('oracles.tables.0.entries.1.key', 'patrol'), 'oracles.tables[0].entries[1].key: duplicate entry key "patrol"'];
        yield 'unknown entry property' => [self::set('oracles.tables.0.entries.0.note', 'x'), 'oracles.tables[0].entries[0].note: unknown property'];
        yield 'entries still checked by Randomness' => [self::set('oracles.tables.0.entries.1.min', 3), 'oracles.tables: '];
        yield 'unknown chaos property' => [self::set('oracles.likelihood.0.chaos.step', 1), 'oracles.likelihood[0].chaos.step: unknown property'];
        yield 'bad chaos tracker key' => [self::set('oracles.likelihood.0.chaos.tracker', 'Chaos'), 'oracles.likelihood[0].chaos.tracker: must be 1 to 64 characters'];
        yield 'unknown chaos tracker' => [self::set('oracles.likelihood.0.chaos.tracker', 'noise'), 'oracles.likelihood[0].chaos.tracker: unknown tracker "noise"'];
        yield 'chaos tracker a clock' => [self::set('oracles.likelihood.0.chaos.tracker', 'alarm'), 'oracles.likelihood[0].chaos.tracker: must be a counter, "alarm" is a clock'];
        yield 'chaos tracker of another range' => [self::set('trackers.2.max', 10), 'oracles.likelihood[0].chaos.tracker: counter "chaos" must range 1..9 like the chaos factor, 1..10 given'];
        yield 'chaos still checked by Randomness' => [self::set('oracles.likelihood.0.chaos.neutral', 10), 'oracles.likelihood[0]: '];

        // Trackers.
        yield 'trackers not a list' => [self::set('trackers', 'alarm'), 'trackers: must be a list'];
        yield 'too many trackers' => [self::set('trackers', array_map(static fn (int $i): array => ['key' => 't-'.$i, 'name' => 'T', 'kind' => 'clock', 'segments' => 2], range(1, 51))), 'trackers: at most 50 trackers, 51 given'];
        yield 'tracker not an object' => [self::set('trackers.0', 'alarm'), 'trackers[0]: must be an object'];
        yield 'tracker without kind' => [self::unset('trackers.0.kind'), 'trackers[0].kind: required'];
        yield 'unknown tracker kind' => [self::set('trackers.0.kind', 'dial'), 'trackers[0].kind: must be one of "counter", "clock", \'dial\' given'];
        yield 'clock with counter fields' => [self::set('trackers.0.min', 0), 'trackers[0].min: unknown property'];
        yield 'counter with clock fields' => [self::set('trackers.1.segments', 4), 'trackers[1].segments: unknown property'];
        yield 'duplicate tracker key' => [self::set('trackers.1.key', 'alarm'), 'trackers[1].key: duplicate tracker key "alarm"'];
        yield 'blank tracker name' => [self::set('trackers.0.name', ' '), 'trackers[0].name: must not be blank'];
        yield 'too long tracker name' => [self::set('trackers.0.name', str_repeat('n', 101)), 'trackers[0].name: must be at most 100 characters'];
        yield 'too long tracker hint' => [self::set('trackers.0.hint', str_repeat('h', 501)), 'trackers[0].hint: must be at most 500 characters'];
        yield 'no clock segments' => [self::set('trackers.0.segments', 0), 'trackers[0].segments: must be at least 1, 0 given'];
        yield 'too many clock segments' => [self::set('trackers.0.segments', 21), 'trackers[0].segments: must be at most 20, 21 given'];
        yield 'fractional clock segments' => [self::set('trackers.0.segments', 2.5), 'trackers[0].segments: must be an integer'];
        yield 'counter min below bound' => [$tracker(['min' => -1001] + $counter), 'trackers[1].min: must be at least -1000, -1001 given'];
        yield 'counter max above bound' => [$tracker(['max' => 1001] + $counter), 'trackers[1].max: must be at most 1000, 1001 given'];
        yield 'counter max below min' => [$tracker(['min' => 3, 'max' => 0] + $counter), 'trackers[1].max: must be at least min (3), 0 given'];
        yield 'counter initial above max' => [$tracker(['initial' => 4] + $counter), 'trackers[1].initial: must be within min..max (0..3), 4 given'];
        yield 'counter initial below min' => [$tracker(['initial' => -1] + $counter), 'trackers[1].initial: must be within min..max (0..3), -1 given'];
        yield 'levels not a list' => [self::set('trackers.1.levels', 'low'), 'trackers[1].levels: must be a list'];
        yield 'last level with upTo' => [self::set('trackers.1.levels.1.upTo', 3), 'trackers[1].levels[1].upTo: the last level catches the rest and must omit upTo'];
        yield 'level without upTo before the last' => [self::unset('trackers.1.levels.0.upTo'), 'trackers[1].levels[0].upTo: required, only the last level omits it'];
        yield 'level upTo not increasing' => [self::set('trackers.3.levels.1.upTo', -1), 'trackers[3].levels[1].upTo: must be greater than -1, the previous upTo, -1 given'];
        yield 'level upTo a tracker' => [self::set('trackers.1.levels.0.upTo', ['tracker' => 'alarm']), 'trackers[1].levels[0].upTo: must be an integer'];
        yield 'unknown level property' => [self::set('trackers.1.levels.0.color', 'red'), 'trackers[1].levels[0].color: unknown property'];
        yield 'empty level label' => [self::set('trackers.1.levels.0.label', ''), 'trackers[1].levels[0].label: must not be empty'];
        yield 'too many levels' => [self::set('trackers.1.levels', [...array_map(static fn (int $i): array => ['upTo' => $i, 'label' => 'L'], range(1, 20)), ['label' => 'Rest']]), 'trackers[1].levels: at most 20 levels, 21 given'];

        // Fact slots.
        yield 'fact slots not a list' => [self::set('factSlots', 'target'), 'factSlots: must be a list'];
        yield 'unknown fact slot type' => [self::set('factSlots.0.type', 'place'), 'factSlots[0].type: must be one of "text", "npc", "thread", \'place\' given'];
        yield 'unknown fact slot property' => [self::set('factSlots.0.hint', 'Who?'), 'factSlots[0].hint: unknown property'];
        yield 'duplicate fact slot key' => [self::set('factSlots.1.key', 'target'), 'factSlots[1].key: duplicate fact slot key "target"'];
        yield 'blank fact slot label' => [self::set('factSlots.0.label', ' '), 'factSlots[0].label: must not be blank'];
        yield 'too long fact slot label' => [self::set('factSlots.0.label', str_repeat('l', 101)), 'factSlots[0].label: must be at most 100 characters'];
        yield 'too many fact slots' => [self::set('factSlots', array_map(static fn (int $i): array => ['key' => 's-'.$i, 'label' => 'S', 'type' => 'text'], range(1, 101))), 'factSlots: at most 100 fact slots, 101 given'];

        yield from self::invalidSceneTypes();
        yield from self::invalidFlows();
    }

    /**
     * @return iterable<string, array{array<mixed>, non-empty-string}>
     */
    private static function invalidFlows(): iterable
    {
        $noise = self::withNoiseAndDawn();
        $dawnReached = self::example('flows.0.oracles.2', 'heist-scenes', $noise);

        // Flows, phases and selections.
        yield 'flows not a list' => [self::example('flows', 'heist'), 'flows: must be a list'];
        yield 'too many flows' => [self::example('flows', array_map(static fn (int $i): array => self::flowSummary('f-'.$i), range(1, 21))), 'flows: at most 20 flows, 21 given'];
        yield 'duplicate flow key' => [self::example('flows.1', self::flowSummary('heist')), 'flows[1].key: duplicate flow key "heist"'];
        yield 'two default flows' => [self::example('flows.1', [...self::flowSummary('quick'), 'default' => true]), 'flows[1].default: at most one flow is the default, "heist" already is'];
        yield 'default not a boolean' => [self::example('flows.0.default', 'yes'), 'flows[0].default: must be a boolean'];
        yield 'blank flow name' => [self::example('flows.0.name', ' '), 'flows[0].name: must not be blank'];
        yield 'too long flow description' => [self::example('flows.0.description', str_repeat('d', 2001)), 'flows[0].description: must be at most 2000 characters'];
        yield 'too long introduction' => [self::example('flows.0.introduction', str_repeat('i', 5001)), 'flows[0].introduction: must be at most 5000 characters'];
        yield 'unknown default view' => [self::example('flows.0.defaultView', 'map'), 'flows[0].defaultView: must be one of "focus", "journal", \'map\' given'];
        yield 'unknown flow property' => [self::example('flows.0.theme', 'noir'), 'flows[0].theme: unknown property'];
        yield 'unknown flow oracle' => [self::example('flows.0.oracles.0', 'dice'), 'flows[0].oracles[0]: unknown oracle "dice"'];
        yield 'duplicate flow tracker' => [self::example('flows.0.trackers.1', 'alarm'), 'flows[0].trackers[1]: duplicate tracker "alarm"'];
        yield 'unknown flow tracker' => [self::example('flows.0.trackers.2', 'noise'), 'flows[0].trackers[2]: unknown tracker "noise"'];
        yield 'no phases' => [self::example('flows.0.phases', []), 'flows[0].phases: at least 1 phases, 0 given'];
        yield 'too many phases' => [self::example('flows.0.phases', array_map(static fn (int $i): array => ['key' => 'p-'.$i, 'name' => 'P', 'mode' => 'once', 'selection' => ['rule' => 'player', 'sceneTypes' => ['legwork']]], range(1, 21))), 'flows[0].phases: at most 20 phases, 21 given'];
        yield 'duplicate phase key' => [self::example('flows.0.phases.1.key', 'legwork'), 'flows[0].phases[1].key: duplicate phase key "legwork"'];
        yield 'bad phase key' => [self::example('flows.0.phases.1.key', 'The heist'), 'flows[0].phases[1].key: must be 1 to 64 characters'];
        yield 'empty act' => [self::example('flows.0.phases.0.act', ''), 'flows[0].phases[0].act: must not be empty'];
        yield 'unknown phase mode' => [self::example('flows.0.phases.0.mode', 'always'), 'flows[0].phases[0].mode: must be one of "once", "loop", \'always\' given'];
        yield 'unknown hook' => [self::example('flows.0.phases.0.dawn', []), 'flows[0].phases[0].dawn: unknown property'];
        yield 'hook not a list' => [self::example('flows.0.phases.0.worldTurn', 'roll'), 'flows[0].phases[0].worldTurn: must be a list'];
        yield 'hook steps checked like Scene Type steps' => [self::example('flows.0.phases.1.sceneOpening.0.next', 'pressure'), 'flows[0].phases[1].sceneOpening[0].next: must name a later step'];
        yield 'hook step key end' => [self::example('flows.0.phases.0.sessionOpening.0.key', 'end'), 'flows[0].phases[0].sessionOpening[0].key: "end" is reserved'];
        yield 'missing selection' => [self::unset('flows.0.phases.0.selection', self::example()), 'flows[0].phases[0].selection: required'];
        yield 'unknown selection rule' => [self::example('flows.0.phases.0.selection.rule', 'random'), 'flows[0].phases[0].selection.rule: must be one of "sequence", "player", "oracle", \'random\' given'];
        yield 'selection naming an unknown Scene Type' => [self::example('flows.0.phases.0.selection.sceneTypes.0', 'dawn'), 'flows[0].phases[0].selection.sceneTypes[0]: unknown Scene Type "dawn"'];
        yield 'empty selection' => [self::example('flows.0.phases.0.selection.sceneTypes', []), 'flows[0].phases[0].selection.sceneTypes: at least 1 Scene Types, 0 given'];
        yield 'too many selected Scene Types' => [self::example('flows.0.phases.2.selection.sceneTypes', array_fill(0, 21, 'firefight')), 'flows[0].phases[2].selection.sceneTypes: at most 20 Scene Types, 21 given'];
        yield 'player selection repeating a Scene Type' => [self::example('flows.0.phases.0.selection.sceneTypes', ['legwork', 'legwork']), 'flows[0].phases[0].selection.sceneTypes[1]: duplicate Scene Type "legwork"'];
        yield 'oracle selection naming a likelihood oracle' => [self::example('flows.0.phases.1.selection.table', 'fate'), 'flows[0].phases[1].selection.table: unknown oracle table "fate"'];
        yield 'oracle selection with Scene Types' => [self::example('flows.0.phases.1.selection.sceneTypes', ['legwork']), 'flows[0].phases[1].selection.sceneTypes: unknown property'];
        yield 'oracle selection table with an entry naming no Scene Type' => [
            self::example('flows.0.phases.1.selection.table', 'complications'),
            'flows[0].phases[1].selection.table: every entry of table "complications" must name a sceneType, oracles.tables[1].entries[0] does not',
        ];

        // References inside a flow.
        yield 'Scene Type tracker outside the flow' => [self::example('flows.0.trackers', ['alarm', 'chaos']), 'sceneTypes[0].closing[0].options[0].effects[0].tracker: tracker "edge" is not one of the trackers of flow "heist"'];
        yield 'phase tracker outside the flow' => [self::example('flows.0.trackers', ['edge', 'chaos']), 'flows[0].phases[0].worldTurn[0].bands[0].effects[0].tracker: tracker "alarm" is not one of the trackers of flow "heist"'];
        yield 'condition on a tracker outside the flow' => [self::example('flows.0.phases.1.sceneOpening.0.tracker', 'noise', $noise), 'flows[0].phases[1].sceneOpening[0].tracker: tracker "noise" is not one of the trackers of flow "heist"'];
        yield 'band bound by a tracker outside the flow' => [self::example('flows.0.phases.1.sceneOpening.0.bands.0.upTo', ['tracker' => 'noise'], $noise), 'flows[0].phases[1].sceneOpening[0].bands[0].upTo.tracker: tracker "noise" is not one of the trackers of flow "heist"'];
        yield 'effect valued by a tracker outside the flow' => [self::example('sceneTypes.2.setup.0.effects.0.value', ['tracker' => 'noise'], $noise), 'sceneTypes[2].setup[0].effects[0].value.tracker: tracker "noise" is not one of the trackers of flow "heist"'];
        yield 'rolled entry effect on a tracker outside the flow' => [self::example('oracles.tables.1.entries.0.effects.0.tracker', 'noise', $noise), 'oracles.tables[1].entries[0].effects[0].tracker: tracker "noise" is not one of the trackers of flow "heist"'];
        yield 'Scene Type reached through a nextScene' => [self::example('flows.0.phases.1.worldTurn.1.bands.0.effects.0.sceneType', 'dawn', $dawnReached), 'sceneTypes[3].play[0].effects[0].tracker: tracker "noise" is not one of the trackers of flow "heist"'];
        yield 'Scene Type reached through a rolled entry' => [self::example('oracles.tables.1.entries.2.sceneType', 'dawn', $dawnReached), 'sceneTypes[3].play[0].effects[0].tracker: tracker "noise" is not one of the trackers of flow "heist"'];
        yield 'Scene Type reached through a nested table' => [
            self::example('oracles.tables.2', ['key' => 'dawns', 'name' => 'Dawns', 'entries' => [['text' => 'Sunrise', 'sceneType' => 'dawn']]], self::example('oracles.tables.1.entries.0.table', 'dawns', $dawnReached)),
            'sceneTypes[3].play[0].effects[0].tracker: tracker "noise" is not one of the trackers of flow "heist"',
        ];
        yield 'Scene Type shortcut outside the flow oracles' => [self::example('flows.0.oracles', ['fate']), 'sceneTypes[1].oracles[1]: oracle "complications" is not one of the oracles of flow "heist"'];

        // Placeholders.
        yield 'unsupported placeholder namespace' => [self::example('sceneTypes.0.setup.0.prompt', 'About {fact:target}'), 'sceneTypes[0].setup[0].prompt: placeholder {fact:target} is not supported in schema version 2'];
        yield 'later-feature placeholder' => [self::example('sceneTypes.0.setup.0.title', '{picked} joins'), 'sceneTypes[0].setup[0].title: placeholder {picked} is not supported in schema version 2'];
        yield 'tracker placeholder without key' => [self::example('sceneTypes.0.setup.0.title', 'At {tracker}'), 'sceneTypes[0].setup[0].title: placeholder {tracker} is not supported in schema version 2'];
        yield 'answer placeholder with a key' => [self::example('sceneTypes.2.setup.0.effects.1.title', '{answer:x}'), 'sceneTypes[2].setup[0].effects[1].title: placeholder {answer:x} is not supported in schema version 2'];
        yield 'answer placeholder in a step' => [self::example('sceneTypes.0.setup.0.tip', 'You said {answer}'), 'sceneTypes[0].setup[0].tip: placeholder {answer} is only allowed in effect text'];
        yield 'unknown tracker placeholder' => [self::example('sceneTypes.0.setup.0.title', 'At {tracker:noise}'), 'sceneTypes[0].setup[0].title: placeholder {tracker:noise}: unknown tracker "noise"'];
        yield 'unknown step placeholder' => [self::example('flows.0.phases.2.phaseClosing.0.title', 'Was {step:nowhere} worth it?'), 'flows[0].phases[2].phaseClosing[0].title: placeholder {step:nowhere}: unknown step "nowhere"'];
        yield 'unknown placeholder outside any flow' => [self::scenes('sceneTypes.0.setup.0.prompt', 'At {step:nowhere}'), 'sceneTypes[0].setup[0].prompt: placeholder {step:nowhere}: unknown step "nowhere"'];
        yield 'tracker placeholder outside the flow' => [self::example('flows.0.phases.1.sceneOpening.1.prompt', 'Noise at {tracker:noise}', $noise), 'flows[0].phases[1].sceneOpening[1].prompt: placeholder {tracker:noise}: tracker "noise" is not one of the trackers of flow "heist"'];
        yield 'step placeholder outside the flow' => [self::example('flows.0.phases.2.phaseClosing.0.title', 'After {step:sunrise}', $noise), 'flows[0].phases[2].phaseClosing[0].title: placeholder {step:sunrise}: step "sunrise" is not in flow "heist" or its Scene Types'];
        yield 'unknown tracker placeholder in a rolled entry effect' => [
            self::example('oracles.tables.1.entries.0.effects.1', ['kind' => 'sceneTitle', 'title' => 'Patrol at {tracker:noise}']),
            'oracles.tables[1].entries[0].effects[1].title: placeholder {tracker:noise}: unknown tracker "noise"',
        ];
    }

    /**
     * @return iterable<string, array{array<mixed>, non-empty-string}>
     */
    private static function invalidSceneTypes(): iterable
    {
        $choice = 'sceneTypes.0.closing.0';
        $condition = static fn (array $bands): array => self::scenes('sceneTypes.3.closing.0.bands', $bands);
        $effect = static fn (array $effect): array => self::scenes('sceneTypes.2.setup.0.effects.0', $effect);

        // Table entries.
        yield 'unknown entry Scene Type' => [self::scenes('oracles.tables.0.entries.1.sceneType', 'dawn'), 'oracles.tables[0].entries[1].sceneType: unknown Scene Type "dawn"'];
        yield 'entry Scene Type an empty list' => [self::scenes('oracles.tables.0.entries.1.sceneType', []), 'oracles.tables[0].entries[1].sceneType: must be a string'];
        yield 'unknown tracker in an entry effect' => [self::scenes('oracles.tables.0.entries.0.effects.0.tracker', 'noise'), 'oracles.tables[0].entries[0].effects[0].tracker: unknown tracker "noise"'];

        // Scene Types.
        yield 'duplicate Scene Type key' => [self::scenes('sceneTypes.1.key', 'legwork'), 'sceneTypes[1].key: duplicate Scene Type key "legwork"'];
        yield 'missing purpose' => [self::unset('sceneTypes.0.purpose', self::scenes()), 'sceneTypes[0].purpose: required'];
        yield 'too long purpose' => [self::scenes('sceneTypes.0.purpose', str_repeat('p', 501)), 'sceneTypes[0].purpose: must be at most 500 characters'];
        yield 'too long tips' => [self::scenes('sceneTypes.2.tips', str_repeat('t', 2001)), 'sceneTypes[2].tips: must be at most 2000 characters'];
        yield 'missing part' => [self::unset('sceneTypes.0.play', self::scenes()), 'sceneTypes[0].play: required'];
        yield 'unknown shortcut oracle' => [self::scenes('sceneTypes.0.oracles.0', 'dice'), 'sceneTypes[0].oracles[0]: unknown oracle "dice"'];
        yield 'duplicate shortcut oracle' => [self::scenes('sceneTypes.1.oracles.1', 'fate'), 'sceneTypes[1].oracles[1]: duplicate oracle "fate"'];
        yield 'too many Scene Types' => [self::scenes('sceneTypes', array_map(static fn (int $i): array => ['key' => 'st-'.$i, 'name' => 'S', 'purpose' => 'P', 'oracles' => [], 'setup' => [], 'play' => [], 'closing' => []], range(1, 101))), 'sceneTypes: at most 100 Scene Types, 101 given'];

        // Steps.
        yield 'too many steps' => [self::scenes('sceneTypes.0.play', array_map(static fn (int $i): array => ['key' => 's-'.$i, 'kind' => 'prompt', 'title' => 'S'], range(1, 51))), 'sceneTypes[0].play: at most 50 steps, 51 given'];
        yield 'step without kind' => [self::unset('sceneTypes.0.setup.0.kind', self::scenes()), 'sceneTypes[0].setup[0].kind: required'];
        yield 'unknown step kind' => [self::scenes('sceneTypes.0.setup.0.kind', 'pick'), 'sceneTypes[0].setup[0].kind: must be one of "prompt", "oracle", "table", "roll", "choice", "condition", \'pick\' given'];
        yield 'field of another kind' => [self::scenes('sceneTypes.0.setup.0.dice', '1d6'), 'sceneTypes[0].setup[0].dice: unknown property'];
        yield 'step key end' => [self::scenes('sceneTypes.0.setup.0.key', 'end'), 'sceneTypes[0].setup[0].key: "end" is reserved'];
        yield 'duplicate step key in one list' => [self::scenes('sceneTypes.3.setup.1.key', 'how'), 'sceneTypes[3].setup[1].key: duplicate step key "how"'];
        yield 'missing step title' => [self::unset('sceneTypes.0.setup.0.title', self::scenes()), 'sceneTypes[0].setup[0].title: required'];
        yield 'too long step prompt' => [self::scenes('sceneTypes.0.setup.0.prompt', str_repeat('p', 2001)), 'sceneTypes[0].setup[0].prompt: must be at most 2000 characters'];
        yield 'mandatory not a boolean' => [self::scenes('sceneTypes.0.setup.0.mandatory', 'yes'), 'sceneTypes[0].setup[0].mandatory: must be a boolean'];
        yield 'next naming an earlier step' => [self::scenes('sceneTypes.3.setup.2.next', 'how'), 'sceneTypes[3].setup[2].next: must name a later step of the same list or "end", "how" given'];
        yield 'next naming its own step' => [self::scenes('sceneTypes.3.setup.2.next', 'route'), 'sceneTypes[3].setup[2].next: must name a later step'];
        yield 'next naming a step of another list' => [self::scenes('sceneTypes.3.setup.0.next', 'ask'), 'sceneTypes[3].setup[0].next: must name a later step'];
        yield 'band next naming an earlier step' => [self::scenes('sceneTypes.3.setup.3.bands.2.next', 'route'), 'sceneTypes[3].setup[3].bands[2].next: must name a later step'];
        yield 'bad next key' => [self::scenes('sceneTypes.3.setup.0.next', 'Route'), 'sceneTypes[3].setup[0].next: must be 1 to 64 characters'];

        // Oracle and table steps.
        yield 'oracle step naming a table' => [self::scenes('sceneTypes.1.play.0.oracle', 'complications'), 'sceneTypes[1].play[0].oracle: unknown likelihood oracle "complications"'];
        yield 'unknown likelihood level' => [self::scenes('sceneTypes.1.play.0.likelihood', 'certain'), 'sceneTypes[1].play[0].likelihood: unknown level "certain" of likelihood oracle "fate"'];
        yield 'unknown oracle branch' => [self::scenes('sceneTypes.1.play.0.branches.maybe', []), 'sceneTypes[1].play[0].branches.maybe: unknown property'];
        yield 'oracle branches not an object' => [self::scenes('sceneTypes.1.play.0.branches', ['yes']), 'sceneTypes[1].play[0].branches: must be an object'];
        yield 'oracle branch next naming an earlier step' => [self::scenes('sceneTypes.3.play.0.branches.no.next', 'ask'), 'sceneTypes[3].play[0].branches.no.next: must name a later step'];
        yield 'table step naming a likelihood oracle' => [self::scenes('sceneTypes.1.play.1.table', 'fate'), 'sceneTypes[1].play[1].table: unknown oracle table "fate"'];
        yield 'branch on an entry of another table' => [self::scenes('sceneTypes.1.play.1.branches.0.entry', 'rooftops'), 'sceneTypes[1].play[1].branches[0].entry: unknown entry "rooftops" of table "complications"'];
        yield 'duplicate branch entry' => [self::scenes('sceneTypes.1.play.1.branches.1', ['entry' => 'spotted']), 'sceneTypes[1].play[1].branches[1].entry: duplicate branch entry "spotted"'];
        yield 'unknown otherwise property' => [self::scenes('sceneTypes.1.play.1.otherwise.entry', 'patrol'), 'sceneTypes[1].play[1].otherwise.entry: unknown property'];

        // Roll and choice steps.
        yield 'invalid dice' => [self::scenes('sceneTypes.3.setup.3.dice', '2x6'), 'sceneTypes[3].setup[3].dice: invalid dice notation: '];
        yield 'too many roll bands' => [self::scenes('sceneTypes.3.setup.3.bands', [...array_map(static fn (int $i): array => ['upTo' => $i], range(1, 20)), []]), 'sceneTypes[3].setup[3].bands: at most 20 bands, 21 given'];
        yield 'one option' => [self::unset($choice.'.skip', self::scenes($choice.'.options', [['key' => 'yes', 'label' => 'Yes']])), 'sceneTypes[0].closing[0].options: at least 2 options, 1 given'];
        yield 'duplicate option key' => [self::scenes($choice.'.options.1.key', 'yes'), 'sceneTypes[0].closing[0].options[1].key: duplicate option key "yes"'];
        yield 'skip naming no option' => [self::scenes($choice.'.skip', 'maybe'), 'sceneTypes[0].closing[0].skip: unknown option "maybe"'];
        yield 'suggested choice without skip' => [self::unset($choice.'.skip', self::scenes()), 'sceneTypes[0].closing[0].skip: required, a suggested choice names the option a skip follows'];

        // Condition steps and bands.
        yield 'mandatory condition' => [self::scenes('sceneTypes.3.closing.0.mandatory', true), 'sceneTypes[3].closing[0].mandatory: a condition step is never mandatory'];
        yield 'condition without bands' => [$condition([]), 'sceneTypes[3].closing[0].bands: at least 1 bands, 0 given'];
        yield 'condition on an unknown tracker' => [self::scenes('sceneTypes.3.closing.0.tracker', 'noise'), 'sceneTypes[3].closing[0].tracker: unknown tracker "noise"'];
        yield 'last band with upTo' => [$condition([['upTo' => 3], ['upTo' => 5]]), 'sceneTypes[3].closing[0].bands[1].upTo: the last band catches the rest and must omit upTo'];
        yield 'band without upTo before the last' => [$condition([[], []]), 'sceneTypes[3].closing[0].bands[0].upTo: required, only the last band omits it'];
        yield 'band upTo not increasing across a tracker bound' => [$condition([['upTo' => 3], ['upTo' => ['tracker' => 'edge']], ['upTo' => 3], []]), 'sceneTypes[3].closing[0].bands[2].upTo: must be greater than 3, the previous upTo, 3 given'];
        yield 'band upTo naming an unknown tracker' => [$condition([['upTo' => ['tracker' => 'noise']], []]), 'sceneTypes[3].closing[0].bands[0].upTo.tracker: unknown tracker "noise"'];
        yield 'band upTo with another property' => [$condition([['upTo' => ['tracker' => 'edge', 'plus' => 1]], []]), 'sceneTypes[3].closing[0].bands[0].upTo.plus: unknown property'];
        yield 'band upTo a string' => [$condition([['upTo' => '3'], []]), 'sceneTypes[3].closing[0].bands[0].upTo: must be an integer or {tracker: key}'];
        yield 'band upTo a fractional number' => [$condition([['upTo' => 2.5], []]), 'sceneTypes[3].closing[0].bands[0].upTo: must be an integer or {tracker: key}'];
        yield 'unknown band property' => [$condition([['upTo' => 3, 'label' => 'Low'], []]), 'sceneTypes[3].closing[0].bands[0].label: unknown property'];

        // Effects.
        yield 'too many effects' => [self::scenes('sceneTypes.2.setup.0.effects', array_fill(0, 11, ['kind' => 'endPhase'])), 'sceneTypes[2].setup[0].effects: at most 10 effects, 11 given'];
        yield 'effect without kind' => [$effect(['tracker' => 'alarm']), 'sceneTypes[2].setup[0].effects[0].kind: required'];
        yield 'unknown effect kind' => [$effect(['kind' => 'createNpc']), 'sceneTypes[2].setup[0].effects[0].kind: must be one of "tracker", "nextScene", "switchSceneType", "endPhase", "sceneTitle", \'createNpc\' given'];
        yield 'unknown effect tracker' => [$effect(['kind' => 'tracker', 'tracker' => 'noise', 'op' => 'add', 'value' => 1]), 'sceneTypes[2].setup[0].effects[0].tracker: unknown tracker "noise"'];
        yield 'unknown effect op' => [$effect(['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'multiply', 'value' => 2]), 'sceneTypes[2].setup[0].effects[0].op: must be one of "add", "set", \'multiply\' given'];
        yield 'effect value too high' => [$effect(['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => 1001]), 'sceneTypes[2].setup[0].effects[0].value: must be at most 1000, 1001 given'];
        yield 'fractional effect value' => [$effect(['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'add', 'value' => 1.5]), 'sceneTypes[2].setup[0].effects[0].value: must be an integer or {tracker: key}'];
        yield 'effect value naming an unknown tracker' => [$effect(['kind' => 'tracker', 'tracker' => 'alarm', 'op' => 'set', 'value' => ['tracker' => 'noise']]), 'sceneTypes[2].setup[0].effects[0].value.tracker: unknown tracker "noise"'];
        yield 'nextScene naming an unknown Scene Type' => [$effect(['kind' => 'nextScene', 'sceneType' => 'dawn']), 'sceneTypes[2].setup[0].effects[0].sceneType: unknown Scene Type "dawn"'];
        yield 'switchSceneType without Scene Type' => [$effect(['kind' => 'switchSceneType']), 'sceneTypes[2].setup[0].effects[0].sceneType: required'];
        yield 'endPhase with a field' => [$effect(['kind' => 'endPhase', 'sceneType' => 'legwork']), 'sceneTypes[2].setup[0].effects[0].sceneType: unknown property'];
        yield 'too long scene title' => [$effect(['kind' => 'sceneTitle', 'title' => str_repeat('t', 101)]), 'sceneTypes[2].setup[0].effects[0].title: must be at most 100 characters'];
    }

    /**
     * @param array<mixed>     $release
     * @param non-empty-string $expectedPrefix
     */
    #[Test]
    #[DataProvider('invalidReleases')]
    public function itRejectsInvalidContentNamingThePath(array $release, string $expectedPrefix): void
    {
        try {
            ReleaseContent::fromArray($release);
            self::fail('Expected InvalidReleaseContent.');
        } catch (InvalidReleaseContent $invalidReleaseContent) {
            self::assertStringStartsWith($expectedPrefix, $invalidReleaseContent->getMessage());
        }
    }
}
