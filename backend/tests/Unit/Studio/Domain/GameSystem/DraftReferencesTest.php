<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\GameSystem;

use App\Studio\Domain\GameSystem\DraftReferences;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DraftReferences::class)]
final class DraftReferencesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        /** @var array<string, mixed> $document */
        $document = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-every-part'))->toArray();

        return $document;
    }

    #[Test]
    public function aTableIsReferencedByScenetypesFlowsSelectionsStepsAndNestedEntries(): void
    {
        $paths = DraftReferences::oraclePathsTo($this->document(), 'escape-routes');

        self::assertSame([
            'sceneTypes[3].oracles',
            'flows[0].oracles',
            'sceneTypes[3].setup[2]',
            'oracles.tables[1].entries[3].table',
        ], $paths);
    }

    #[Test]
    public function aTableIsReferencedByAnOracleSelectionPhase(): void
    {
        self::assertSame(
            ['sceneTypes[4].oracles', 'flows[0].phases[1].selection'],
            DraftReferences::oraclePathsTo($this->document(), 'heist-scenes'),
        );
    }

    #[Test]
    public function aLikelihoodOracleIsReferencedByScenetypesFlowsAndOracleSteps(): void
    {
        $paths = DraftReferences::oraclePathsTo($this->document(), 'fate');

        self::assertSame([
            'sceneTypes[0].oracles',
            'sceneTypes[1].oracles',
            'sceneTypes[2].oracles',
            'flows[0].oracles',
            'flows[1].oracles',
            'sceneTypes[1].play[0]',
            'sceneTypes[3].play[0]',
        ], $paths);
    }

    #[Test]
    public function anInspirationTableAndARandomEventTableAreReferences(): void
    {
        $inspiration = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-inspiration'))->toArray();
        self::assertSame(['sceneTypes[0].play[0].inspiration', 'sceneTypes[0].play[1].inspiration'], DraftReferences::oraclePathsTo($inspiration, 'verbs'));
        self::assertSame(['sceneTypes[0].play[0].inspiration'], DraftReferences::oraclePathsTo($inspiration, 'nouns'));

        $event = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-random-event'))->toArray();
        self::assertSame(['oracles.likelihood[0].randomEvent'], DraftReferences::oraclePathsTo($event, 'focus'));
    }

    #[Test]
    public function theEntriesOfTheExcludedTableDoNotCount(): void
    {
        $document = $this->document();
        $document['oracles'] = ['tables' => [['key' => 'a', 'entries' => [['table' => 'a']]], ['key' => 'b', 'entries' => [['table' => 'a']]]], 'likelihood' => []];

        self::assertSame(['oracles.tables[0].entries[0].table', 'oracles.tables[1].entries[0].table'], DraftReferences::oraclePathsTo($document, 'a'));
        self::assertSame(['oracles.tables[1].entries[0].table'], DraftReferences::oraclePathsTo($document, 'a', 'a'));
    }

    #[Test]
    public function anUnusedKeyHasNoReferences(): void
    {
        self::assertSame([], DraftReferences::oraclePathsTo($this->document(), 'nothing'));
    }

    #[Test]
    public function tableStepBranchesReferenceEntryKeys(): void
    {
        $document = $this->document();

        self::assertSame(['sceneTypes[1].play[1].branches[0].entry'], DraftReferences::entryPathsTo($document, 'complications', 'spotted'));
        self::assertSame(['sceneTypes[3].setup[2].branches[1].entry'], DraftReferences::entryPathsTo($document, 'escape-routes', 'sewers'));
        self::assertSame([], DraftReferences::entryPathsTo($document, 'complications', 'patrol'));
        self::assertSame([], DraftReferences::entryPathsTo($document, 'escape-routes', 'spotted'));
    }

    /**
     * A draft whose only Scene Type plays this one step, at "sceneTypes[0].play[0]".
     *
     * @param array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    private static function step(array $step): array
    {
        return ['sceneTypes' => [['key' => 'legwork', 'setup' => [], 'play' => [$step], 'closing' => []]]];
    }

    /**
     * A draft whose only step carries this one effect, at "sceneTypes[0].play[0].effects[0]".
     *
     * @param array<string, mixed> $effect
     *
     * @return array<string, mixed>
     */
    private static function effect(array $effect): array
    {
        return self::step(['key' => 'ask', 'kind' => 'prompt', 'title' => 'Ask', 'effects' => [$effect]]);
    }

    /**
     * @return iterable<string, array{'tracker'|'sceneType'|'tag'|'factSlot', string, array<string, mixed>, list<string>}>
     */
    public static function references(): iterable
    {
        $step = 'sceneTypes[0].play[0]';
        $effect = $step.'.effects[0]';
        $tracker = ['kind' => 'tracker', 'tracker' => 'edge', 'op' => 'add'];

        // Trackers.
        yield 'flow trackers' => ['tracker', 'heat', ['flows' => [['key' => 'heist', 'trackers' => ['edge', 'heat']]]], ['flows[0].trackers']];
        yield 'tracker effect' => ['tracker', 'heat', self::effect([...$tracker, 'tracker' => 'heat', 'value' => 1]), [$effect]];
        yield 'tracker effect valued by a tracker' => ['tracker', 'heat', self::effect([...$tracker, 'value' => ['tracker' => 'heat']]), [$effect]];
        yield 'tracker effect valued by a formula' => ['tracker', 'heat', self::effect([...$tracker, 'value' => ['formula' => '{tracker:heat} + 1']]), [$effect]];
        yield 'sheet effect valued by a formula' => ['tracker', 'heat', self::effect(['kind' => 'sheet', 'field' => 'hp', 'op' => 'set', 'value' => ['formula' => 'max(1, {tracker:heat})']]), [$effect]];
        yield 'condition on a tracker' => ['tracker', 'heat', self::step(['key' => 'calm', 'kind' => 'condition', 'tracker' => 'heat', 'bands' => [[]]]), [$step]];
        yield 'band bound by a tracker' => ['tracker', 'heat', self::step(['key' => 'r', 'kind' => 'roll', 'dice' => '1d6', 'bands' => [['upTo' => ['tracker' => 'heat']], []]]), [$step.'.bands[0]']];
        yield 'band bound by a formula' => ['tracker', 'heat', self::step(['key' => 'r', 'kind' => 'roll', 'dice' => '1d6', 'bands' => [[], ['upTo' => ['formula' => '{tracker:heat}']]]]), [$step.'.bands[1]']];
        yield 'check difficulty from a tracker' => ['tracker', 'heat', self::step(['key' => 'c', 'kind' => 'check', 'check' => 'shoot', 'difficulty' => ['tracker' => 'heat']]), [$step]];
        yield 'check difficulty from a formula' => ['tracker', 'heat', self::step(['key' => 'c', 'kind' => 'check', 'check' => 'shoot', 'difficulty' => ['formula' => '{tracker:heat} * 2']]), [$step]];
        yield 'tracker placeholder in a step' => ['tracker', 'heat', self::step(['key' => 'p', 'kind' => 'prompt', 'title' => 'T', 'tip' => 'Heat is {tracker:heat}']), [$step]];
        yield 'tracker placeholder in effect text' => ['tracker', 'heat', self::effect(['kind' => 'sceneTitle', 'title' => 'At {tracker:heat}']), [$effect]];
        yield 'chaos tracker' => ['tracker', 'heat', ['oracles' => ['likelihood' => [['key' => 'fate'], ['key' => 'omen', 'chaos' => ['tracker' => 'heat']]]]], ['oracles.likelihood[1].chaos']];
        yield 'table entry effect' => ['tracker', 'heat', ['oracles' => ['tables' => [['key' => 'events', 'entries' => [['text' => 'Calm'], ['text' => 'Patrol', 'effects' => [[...$tracker, 'tracker' => 'heat']]]]]]]], ['oracles.tables[0].entries[1].effects[0]']];
        yield 'derived sheet field' => ['tracker', 'heat', ['sheet' => ['fields' => [['key' => 'hp', 'type' => 'number'], ['key' => 'cool', 'type' => 'derived', 'formula' => '10 - {tracker:heat}']]]], ['sheet.fields[1]']];
        yield 'check modifier' => ['tracker', 'heat', ['checks' => [['key' => 'shoot', 'modifier' => '{agility} - {tracker:heat}']]], ['checks[0]']];
        yield 'check band bound' => ['tracker', 'heat', ['checks' => [['key' => 'shoot', 'bands' => [['key' => 'miss', 'upTo' => ['tracker' => 'heat']], ['key' => 'hit', 'upTo' => ['formula' => '{tracker:heat} + 3']], ['key' => 'crit']]]]], ['checks[0].bands[0]', 'checks[0].bands[1]']];

        // Every place an effect lives.
        yield 'step effects' => ['tracker', 'heat', self::step(['key' => 's', 'kind' => 'prompt', 'effects' => [[], [...$tracker, 'tracker' => 'heat']]]), [$step.'.effects[1]']];
        yield 'roll band' => ['tracker', 'heat', self::step(['key' => 's', 'kind' => 'roll', 'bands' => [[], ['effects' => [[...$tracker, 'tracker' => 'heat']]]]]), [$step.'.bands[1].effects[0]']];
        yield 'oracle branch' => ['tracker', 'heat', self::step(['key' => 's', 'kind' => 'oracle', 'branches' => ['yes' => [], 'no' => ['effects' => [[...$tracker, 'tracker' => 'heat']]]]]), [$step.'.branches.no.effects[0]']];
        yield 'table branch' => ['tracker', 'heat', self::step(['key' => 's', 'kind' => 'table', 'branches' => [['entry' => 'a', 'effects' => [[...$tracker, 'tracker' => 'heat']]]]]), [$step.'.branches[0].effects[0]']];
        yield 'table otherwise' => ['tracker', 'heat', self::step(['key' => 's', 'kind' => 'table', 'otherwise' => ['effects' => [[...$tracker, 'tracker' => 'heat']]]]), [$step.'.otherwise.effects[0]']];
        yield 'choice option' => ['tracker', 'heat', self::step(['key' => 's', 'kind' => 'choice', 'options' => [['key' => 'a'], ['key' => 'b', 'effects' => [[...$tracker, 'tracker' => 'heat']]]]]), [$step.'.options[1].effects[0]']];
        yield 'pick branch' => ['tracker', 'heat', self::step(['key' => 's', 'kind' => 'pick', 'branches' => ['found' => ['effects' => [[...$tracker, 'tracker' => 'heat']]]]]), [$step.'.branches.found.effects[0]']];
        yield 'check outcome' => ['tracker', 'heat', self::step(['key' => 's', 'kind' => 'check', 'outcomes' => [['band' => 'hit', 'effects' => [[...$tracker, 'tracker' => 'heat']]]]]), [$step.'.outcomes[0].effects[0]']];
        yield 'Scene Type setup and closing' => ['tracker', 'heat', ['sceneTypes' => [['setup' => [['kind' => 'condition', 'tracker' => 'heat']], 'closing' => [[], ['kind' => 'condition', 'tracker' => 'heat']]]]], ['sceneTypes[0].setup[0]', 'sceneTypes[0].closing[1]']];
        yield 'phase hooks and world turn' => ['tracker', 'heat', ['flows' => [['phases' => [[], [
            ...array_fill_keys(['sessionOpening', 'sessionClosing', 'phaseOpening', 'phaseClosing', 'sceneOpening', 'sceneClosing'], [['kind' => 'condition', 'tracker' => 'heat']]),
            'worldTurn' => [['kind' => 'prompt', 'effects' => [[...$tracker, 'tracker' => 'heat']]]],
        ]]]]], [
            'flows[0].phases[1].sessionOpening[0]', 'flows[0].phases[1].sessionClosing[0]', 'flows[0].phases[1].phaseOpening[0]', 'flows[0].phases[1].phaseClosing[0]',
            'flows[0].phases[1].sceneOpening[0]', 'flows[0].phases[1].sceneClosing[0]', 'flows[0].phases[1].worldTurn[0].effects[0]',
        ]];

        // Scene Types.
        yield 'sequence and player selections' => ['sceneType', 'dawn', ['flows' => [['phases' => [
            ['selection' => ['rule' => 'sequence', 'sceneTypes' => ['legwork', 'dawn']]],
            ['selection' => ['rule' => 'player', 'sceneTypes' => ['dawn']]],
            ['selection' => ['rule' => 'oracle', 'table' => 'dawn']],
        ]]]], ['flows[0].phases[0].selection', 'flows[0].phases[1].selection']];
        yield 'nextScene effect' => ['sceneType', 'dawn', self::effect(['kind' => 'nextScene', 'sceneType' => 'dawn']), [$effect]];
        yield 'switchSceneType effect' => ['sceneType', 'dawn', self::effect(['kind' => 'switchSceneType', 'sceneType' => 'dawn']), [$effect]];
        yield 'table entry Scene Type' => ['sceneType', 'dawn', ['oracles' => ['tables' => [['entries' => [['sceneType' => 'legwork'], ['sceneType' => 'dawn']]]]]], ['oracles.tables[0].entries[1]']];

        // Tags.
        yield 'createNpc tags' => ['tag', 'crew', self::effect(['kind' => 'createNpc', 'tags' => ['leader', 'crew']]), [$effect]];
        yield 'createThread tags' => ['tag', 'crew', self::effect(['kind' => 'createThread', 'tags' => ['crew']]), [$effect]];
        yield 'condition count tags' => ['tag', 'crew', self::step(['key' => 'c', 'kind' => 'condition', 'count' => ['of' => 'npc', 'tags' => ['crew']], 'bands' => [[]]]), [$step]];
        yield 'pick tags' => ['tag', 'crew', self::step(['key' => 'p', 'kind' => 'pick', 'of' => 'npc', 'tags' => ['crew'], 'mode' => 'random']), [$step]];

        // Fact slots.
        yield 'fillFact slot' => ['factSlot', 'city', self::effect(['kind' => 'fillFact', 'slot' => 'city']), [$effect]];
        yield 'fact placeholder in a step' => ['factSlot', 'city', self::step(['key' => 'p', 'kind' => 'prompt', 'title' => 'Back in {fact:city}']), [$step]];
        yield 'fact placeholder in effect text' => ['factSlot', 'city', self::effect(['kind' => 'createNpc', 'agenda' => 'Rule {fact:city}']), [$effect]];
    }

    /**
     * @param 'tracker'|'sceneType'|'tag'|'factSlot' $kind
     * @param array<string, mixed>                   $document
     * @param list<string>                           $paths
     */
    #[Test]
    #[DataProvider('references')]
    public function itListsWhereAKeyIsReferenced(string $kind, string $key, array $document, array $paths): void
    {
        self::assertSame($paths, $this->pathsTo($kind, $document, $key));
        self::assertSame([], $this->pathsTo($kind, $document, 'nothing'));
    }

    #[Test]
    public function aPlaceReferencingAKeyTwiceIsListedOnce(): void
    {
        $document = self::effect(['kind' => 'tracker', 'tracker' => 'heat', 'op' => 'set', 'value' => ['formula' => '{tracker:heat} + {tracker:heat}']]);

        self::assertSame(['sceneTypes[0].play[0].effects[0]'], DraftReferences::trackerPathsTo($document, 'heat'));
    }

    #[Test]
    public function aValidReleaseListsItsReferencesInDocumentOrder(): void
    {
        $document = $this->document();

        self::assertSame([
            'flows[0].trackers',
            'flows[1].trackers',
            'sceneTypes[1].play[0].branches.no.effects[0]',
            'sceneTypes[2].setup[0].effects[0]',
            'sceneTypes[3].setup[0].effects[0]',
            'sceneTypes[3].setup[2].branches[1].effects[0]',
            'flows[0].phases[0].worldTurn[0].bands[0].effects[0]',
            'flows[0].phases[1].sceneOpening[0]',
            'flows[0].phases[1].sceneOpening[1]',
            'flows[0].phases[1].sceneClosing[0]',
            'flows[0].phases[1].worldTurn[0]',
            'oracles.tables[1].entries[0].effects[0]',
            'oracles.tables[1].entries[1].effects[0]',
        ], DraftReferences::trackerPathsTo($document, 'alarm'));
        self::assertSame(['flows[0].trackers', 'oracles.likelihood[0].chaos'], DraftReferences::trackerPathsTo($document, 'chaos'));
        self::assertSame(['flows[0].phases[0].selection', 'flows[1].phases[0].selection'], DraftReferences::sceneTypePathsTo($document, 'legwork'));
    }

    /**
     * @param 'tracker'|'sceneType'|'tag'|'factSlot' $kind
     * @param array<string, mixed>                   $document
     *
     * @return list<string>
     */
    private function pathsTo(string $kind, array $document, string $key): array
    {
        return match ($kind) {
            'tracker' => DraftReferences::trackerPathsTo($document, $key),
            'sceneType' => DraftReferences::sceneTypePathsTo($document, $key),
            'tag' => DraftReferences::tagPathsTo($document, $key),
            'factSlot' => DraftReferences::factSlotPathsTo($document, $key),
        };
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unparsableFormulas(): iterable
    {
        yield 'oversized' => ['{tracker:heat} + '.str_repeat('1 + ', 100).'1'];
        yield 'deeply nested' => [str_repeat('(', 60).'{tracker:heat}'.str_repeat(')', 60)];
        yield 'unbalanced' => ['({tracker:heat} + '];
    }

    /**
     * A formula the parser refuses (InvalidFormula) reads no tracker: the scan neither throws nor
     * lists the place, and the publisher's validation reports the formula itself.
     */
    #[Test]
    #[DataProvider('unparsableFormulas')]
    public function aFormulaTheParserRefusesReadsNothingAndNeverThrows(string $formula): void
    {
        $document = self::effect(['kind' => 'tracker', 'tracker' => 'edge', 'op' => 'set', 'value' => ['formula' => $formula]]);

        self::assertSame(['sceneTypes[0].play[0].effects[0]'], DraftReferences::trackerPathsTo($document, 'edge'));
        self::assertSame([], DraftReferences::trackerPathsTo($document, 'heat'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedDocuments(): iterable
    {
        yield 'empty' => [[]];
        yield 'sections of the wrong type' => [['oracles' => 'x', 'sceneTypes' => 3, 'flows' => 'y']];
        yield 'objects instead of lists' => [['sceneTypes' => ['a' => []], 'flows' => ['a' => []], 'oracles' => ['tables' => ['a' => 1], 'likelihood' => 'z']]];
        yield 'items of the wrong type' => [['sceneTypes' => [1, null, 'x', ['oracles' => 'fate', 'setup' => 'x', 'play' => [1, ['kind' => 'oracle', 'oracle' => ['fate']]]]], 'flows' => [null, ['phases' => 'x'], ['phases' => [3, ['selection' => 'x', 'sceneOpening' => [['kind' => 'table', 'table' => 'other', 'branches' => 'x']]]]]]]];
        yield 'nested parts of the wrong type' => [['oracles' => ['tables' => [['key' => 'a', 'entries' => 'x'], ['entries' => [1, ['table' => []]]]], 'likelihood' => [['randomEvent' => 'x'], 4]]]];
        yield 'narrative parts of the wrong type' => [[
            'oracles' => ['tables' => [['entries' => [['sceneType' => ['fate'], 'effects' => 'x'], ['effects' => [3, ['kind' => 'tracker', 'tracker' => 7]]]]]], 'likelihood' => [['chaos' => 'fate'], ['chaos' => ['tracker' => ['fate']]]]],
            'sheet' => ['fields' => [['formula' => ['fate']], ['type' => 'derived', 'formula' => '{tracker:']]],
            'checks' => 'x',
            'flows' => [['trackers' => 'fate', 'phases' => [['selection' => ['sceneTypes' => 'fate'], 'worldTurn' => [['kind' => 'condition', 'tracker' => ['fate'], 'count' => 'x', 'bands' => [['upTo' => ['formula' => 3]], ['upTo' => ['formula' => '(((']], 'x']]]]]]],
            'sceneTypes' => [['play' => [
                ['kind' => 'pick', 'tags' => 'fate', 'branches' => ['found' => 'x', 'none' => ['effects' => [['kind' => 'createNpc', 'tags' => [['fate']], 'name' => 3]]]]],
                ['kind' => 'check', 'difficulty' => ['tracker' => 3], 'outcomes' => 'x', 'otherwise' => 'x', 'options' => [1], 'title' => ['fate']],
                ['effects' => [['kind' => 'fillFact', 'slot' => []], ['kind' => 'tracker', 'value' => 'fate'], ['kind' => 'nextScene', 'sceneType' => 3], ['kind' => 'sheet', 'value' => ['formula' => null]]]],
            ]]],
        ]];
    }

    /**
     * @param array<string, mixed> $document
     */
    #[Test]
    #[DataProvider('malformedDocuments')]
    public function aMalformedDocumentNeverThrows(array $document): void
    {
        self::assertSame([], DraftReferences::oraclePathsTo($document, 'fate'));
        self::assertSame([], DraftReferences::entryPathsTo($document, 'fate', 'a'));
        self::assertSame([], DraftReferences::trackerPathsTo($document, 'fate'));
        self::assertSame([], DraftReferences::sceneTypePathsTo($document, 'fate'));
        self::assertSame([], DraftReferences::tagPathsTo($document, 'fate'));
        self::assertSame([], DraftReferences::factSlotPathsTo($document, 'fate'));
    }
}
