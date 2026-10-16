<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\GameSystem;

use App\Studio\Domain\GameSystem\OracleReferences;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OracleReferences::class)]
final class OracleReferencesTest extends TestCase
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
        $paths = OracleReferences::pathsTo($this->document(), 'escape-routes');

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
            OracleReferences::pathsTo($this->document(), 'heist-scenes'),
        );
    }

    #[Test]
    public function aLikelihoodOracleIsReferencedByScenetypesFlowsAndOracleSteps(): void
    {
        $paths = OracleReferences::pathsTo($this->document(), 'fate');

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
        self::assertSame(['sceneTypes[0].play[0].inspiration', 'sceneTypes[0].play[1].inspiration'], OracleReferences::pathsTo($inspiration, 'verbs'));
        self::assertSame(['sceneTypes[0].play[0].inspiration'], OracleReferences::pathsTo($inspiration, 'nouns'));

        $event = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-random-event'))->toArray();
        self::assertSame(['oracles.likelihood[0].randomEvent'], OracleReferences::pathsTo($event, 'focus'));
    }

    #[Test]
    public function theEntriesOfTheExcludedTableDoNotCount(): void
    {
        $document = $this->document();
        $document['oracles'] = ['tables' => [['key' => 'a', 'entries' => [['table' => 'a']]], ['key' => 'b', 'entries' => [['table' => 'a']]]], 'likelihood' => []];

        self::assertSame(['oracles.tables[0].entries[0].table', 'oracles.tables[1].entries[0].table'], OracleReferences::pathsTo($document, 'a'));
        self::assertSame(['oracles.tables[1].entries[0].table'], OracleReferences::pathsTo($document, 'a', 'a'));
    }

    #[Test]
    public function anUnusedKeyHasNoReferences(): void
    {
        self::assertSame([], OracleReferences::pathsTo($this->document(), 'nothing'));
    }

    #[Test]
    public function tableStepBranchesReferenceEntryKeys(): void
    {
        $document = $this->document();

        self::assertSame(['sceneTypes[1].play[1].branches[0].entry'], OracleReferences::entryPathsTo($document, 'complications', 'spotted'));
        self::assertSame(['sceneTypes[3].setup[2].branches[1].entry'], OracleReferences::entryPathsTo($document, 'escape-routes', 'sewers'));
        self::assertSame([], OracleReferences::entryPathsTo($document, 'complications', 'patrol'));
        self::assertSame([], OracleReferences::entryPathsTo($document, 'escape-routes', 'spotted'));
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
    }

    /**
     * @param array<string, mixed> $document
     */
    #[Test]
    #[DataProvider('malformedDocuments')]
    public function aMalformedDocumentNeverThrows(array $document): void
    {
        self::assertSame([], OracleReferences::pathsTo($document, 'fate'));
        self::assertSame([], OracleReferences::entryPathsTo($document, 'fate', 'a'));
    }
}
