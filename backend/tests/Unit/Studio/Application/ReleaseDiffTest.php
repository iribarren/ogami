<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\ReleaseDiff;
use App\Studio\Application\ReleaseOutline;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseDiff::class)]
#[CoversClass(ReleaseOutline::class)]
final class ReleaseDiffTest extends TestCase
{
    #[Test]
    public function anItemOnlyTheNewReleaseHasIsAddedInItsOrderAndOneOnlyThePreviousHadIsRemovedInItsOrder(): void
    {
        $before = ['trackers' => [['key' => 'a', 'name' => 'A'], ['key' => 'b', 'name' => 'B'], ['key' => 'c']]];
        $after = ['trackers' => [['key' => 'a', 'name' => 'A'], ['key' => 'z', 'name' => 'Z'], ['key' => 'y']]];

        $trackers = $this->sections($after, $before)['trackers'];

        self::assertSame([['key' => 'z', 'name' => 'Z'], ['key' => 'y', 'name' => null]], $trackers['added']);
        self::assertSame([['key' => 'b', 'name' => 'B'], ['key' => 'c', 'name' => null]], $trackers['removed']);
        self::assertSame([], $trackers['changed']);
        self::assertFalse($trackers['reordered']);
    }

    #[Test]
    public function aChangedPropertyListsTheItemWithTheFieldThatDiffers(): void
    {
        $before = ['trackers' => [['key' => 'heat', 'name' => 'Heat', 'max' => 5], ['key' => 'edge', 'name' => 'Edge']]];
        $after = ['trackers' => [['key' => 'heat', 'name' => 'Heat', 'max' => 6], ['key' => 'edge', 'name' => 'Edge']]];

        $trackers = $this->sections($after, $before)['trackers'];

        self::assertSame([['key' => 'heat', 'name' => 'Heat', 'previousName' => 'Heat', 'fields' => ['max']]], $trackers['changed']);
        self::assertSame([[], []], [$trackers['added'], $trackers['removed']]);
    }

    #[Test]
    public function aRenamedItemKeepsItsKeyAndShowsBothNames(): void
    {
        $before = ['tags' => [['key' => 'noble', 'label' => 'Noble']]];
        $after = ['tags' => [['key' => 'noble', 'label' => 'Aristocrat']]];

        self::assertSame(
            [['key' => 'noble', 'name' => 'Aristocrat', 'previousName' => 'Noble', 'fields' => ['label']]],
            $this->sections($after, $before)['tags']['changed'],
        );
    }

    #[Test]
    public function aPropertyOnlyOneOfTheItemsHasIsAFieldAndTheFieldsAreSorted(): void
    {
        $before = ['trackers' => [['key' => 'heat', 'name' => 'Heat', 'dice' => '1d6', 'bands' => []]]];
        $after = ['trackers' => [['key' => 'heat', 'name' => 'Heat', 'note' => 'new', 'bands' => [1]]]];

        self::assertSame(['bands', 'dice', 'note'], $this->sections($after, $before)['trackers']['changed'][0]['fields']);
    }

    #[Test]
    public function theKeyOrderOfAStoredObjectIsNotAChangeButTheOrderOfAListIs(): void
    {
        $before = ['trackers' => [['key' => 'heat', 'name' => 'Heat', 'bands' => [['from' => 1, 'to' => 2], ['from' => 3, 'to' => 4]], 'style' => ['x' => 1, 'y' => 2]]]];
        $sameContent = ['trackers' => [['style' => ['y' => 2, 'x' => 1], 'bands' => [['to' => 2, 'from' => 1], ['to' => 4, 'from' => 3]], 'name' => 'Heat', 'key' => 'heat']]];
        $reorderedList = ['trackers' => [['key' => 'heat', 'name' => 'Heat', 'bands' => [['from' => 3, 'to' => 4], ['from' => 1, 'to' => 2]], 'style' => ['x' => 1, 'y' => 2]]]];

        self::assertSame([], $this->sections($sameContent, $before)['trackers']['changed']);
        self::assertSame(['bands'], $this->sections($reorderedList, $before)['trackers']['changed'][0]['fields']);
    }

    #[Test]
    public function anEmptyObjectIsTheSameAsAnEmptyListSoTheReleaseModelAndItsStoredCopyAgree(): void
    {
        $model = ['trackers' => [['key' => 'heat', 'bands' => [(object) []], 'style' => (object) ['x' => (object) []]]]];
        $stored = ['trackers' => [['key' => 'heat', 'bands' => [[]], 'style' => ['x' => []]]]];

        self::assertSame([], $this->sections($model, $stored)['trackers']['changed']);
    }

    #[Test]
    public function movingTheKeptItemsIsReorderedAndNotAChange(): void
    {
        $before = ['tags' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B'], ['key' => 'c', 'label' => 'C']]];
        $after = ['tags' => [['key' => 'c', 'label' => 'C'], ['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']]];

        $tags = $this->sections($after, $before)['tags'];

        self::assertTrue($tags['reordered']);
        self::assertSame([[], [], []], [$tags['added'], $tags['removed'], $tags['changed']]);
    }

    #[Test]
    public function addingOrRemovingAnItemAloneIsNotReordered(): void
    {
        $before = ['tags' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B'], ['key' => 'c', 'label' => 'C']]];
        $after = ['tags' => [['key' => 'n', 'label' => 'N'], ['key' => 'a', 'label' => 'A'], ['key' => 'c', 'label' => 'C']]];

        self::assertFalse($this->sections($after, $before)['tags']['reordered']);
    }

    #[Test]
    public function aKeyThatRepeatsCountsOnceByItsFirstOccurrence(): void
    {
        $before = ['tags' => [['key' => 'a', 'label' => 'First'], ['key' => 'a', 'label' => 'Second'], ['key' => 'b', 'label' => 'B']]];
        $after = ['tags' => [['key' => 'b', 'label' => 'B'], ['key' => 'a', 'label' => 'First'], ['key' => 'n', 'label' => 'N1'], ['key' => 'n', 'label' => 'N2']]];

        $tags = $this->sections($after, $before)['tags'];

        self::assertSame([['key' => 'n', 'name' => 'N1']], $tags['added']);
        self::assertSame([], $tags['removed']);
        self::assertSame([], $tags['changed']);
        self::assertTrue($tags['reordered']);
    }

    #[Test]
    public function itemsWithoutAStringKeyShareTheEmptyKeyAndNonArrayItemsHaveNoFields(): void
    {
        $before = ['tags' => ['broken', ['label' => 'Ignored, the key repeats'], ['key' => 7, 'label' => 'Not a string']]];
        $after = ['tags' => ['other', ['label' => 'Ignored too']]];

        $tags = $this->sections($after, $before)['tags'];

        self::assertSame([['key' => '', 'name' => null, 'previousName' => null, 'fields' => []]], $tags['changed']);
        self::assertSame([[], []], [$tags['added'], $tags['removed']]);
    }

    #[Test]
    public function aNonArrayItemAgainstAnObjectListsTheObjectsProperties(): void
    {
        $changed = $this->sections(['tags' => [['label' => 'A']]], ['tags' => ['a']])['tags']['changed'];

        self::assertSame([['key' => '', 'name' => 'A', 'previousName' => null, 'fields' => ['label']]], $changed);
    }

    #[Test]
    public function theGearCatalogIsComparedByKeyLikeTheOtherSections(): void
    {
        $before = ['gear' => [['key' => 'medkit', 'name' => 'Medkit', 'cost' => 20], ['key' => 'rope', 'name' => 'Rope', 'cost' => 5]]];
        $after = ['gear' => [['key' => 'medkit', 'name' => 'First aid kit', 'cost' => 25], ['key' => 'pistol', 'name' => 'Pistol', 'cost' => 50]]];

        $gear = $this->sections($after, $before)['gear'];

        self::assertSame([['key' => 'pistol', 'name' => 'Pistol']], $gear['added']);
        self::assertSame([['key' => 'rope', 'name' => 'Rope']], $gear['removed']);
        self::assertSame([['key' => 'medkit', 'name' => 'First aid kit', 'previousName' => 'Medkit', 'fields' => ['cost', 'name']]], $gear['changed']);
    }

    #[Test]
    public function aReleaseThatGainsOrLosesItsGearCatalogShowsEveryEntryAddedOrRemoved(): void
    {
        $with = ['gear' => [['key' => 'medkit', 'name' => 'Medkit', 'cost' => 20]]];

        self::assertSame([['key' => 'medkit', 'name' => 'Medkit']], $this->sections($with, [])['gear']['added']);
        self::assertSame([['key' => 'medkit', 'name' => 'Medkit']], $this->sections([], $with)['gear']['removed']);
        self::assertSame(['added' => [], 'removed' => [], 'changed' => [], 'reordered' => false], $this->sections([], [])['gear']);
    }

    #[Test]
    public function everySectionOfTheOutlineIsPresentAndTheListsAreDisjoint(): void
    {
        $before = ['trackers' => [['key' => 'a'], ['key' => 'b']], 'flows' => [['key' => 'f', 'name' => 'F']]];
        $after = ['trackers' => [['key' => 'a', 'x' => 1], ['key' => 'c']]];

        $sections = $this->sections($after, $before);

        self::assertSame(array_keys(ReleaseOutline::of([], 2)), array_keys($sections));
        foreach ($sections as $section) {
            $keys = [...array_column($section['added'], 'key'), ...array_column($section['removed'], 'key'), ...array_column($section['changed'], 'key')];
            self::assertSame($keys, array_unique($keys));
        }
        self::assertSame([['key' => 'f', 'name' => 'F']], $sections['flows']['removed']);
    }

    #[Test]
    public function aSchemaVersion1ReleaseMergesTablesAndLikelihoodOraclesAndComparesFlowSteps(): void
    {
        $before = [
            'oracles' => ['tables' => [['key' => 'weather', 'name' => 'Weather', 'dice' => '1d6']], 'likelihood' => [['key' => 'fate', 'name' => 'Fate']]],
            'flow' => ['steps' => [['key' => 'set-scene', 'title' => 'Set the scene']]],
        ];
        $after = [
            'oracles' => ['tables' => [['key' => 'weather', 'name' => 'Weather', 'dice' => '1d8']], 'likelihood' => [['key' => 'odds', 'name' => 'Odds']]],
            'flow' => ['steps' => [['key' => 'set-scene', 'title' => 'Frame the scene']]],
        ];

        $sections = ReleaseDiff::of($after, 1, $before, 1);

        self::assertNotNull($sections);
        self::assertSame(['oracles', 'flowSteps'], array_keys($sections));
        self::assertSame([['key' => 'odds', 'name' => 'Odds']], $sections['oracles']['added']);
        self::assertSame([['key' => 'fate', 'name' => 'Fate']], $sections['oracles']['removed']);
        self::assertSame(['dice'], $sections['oracles']['changed'][0]['fields']);
        self::assertSame([['key' => 'set-scene', 'name' => 'Frame the scene', 'previousName' => 'Set the scene', 'fields' => ['title']]], $sections['flowSteps']['changed']);
    }

    #[Test]
    public function numericKeysSwappedAreReorderedAndNothingElse(): void
    {
        $before = ['trackers' => [['key' => '1', 'name' => 'One'], ['key' => '2', 'name' => 'Two']]];
        $after = ['trackers' => [['key' => '2', 'name' => 'Two'], ['key' => '1', 'name' => 'One']]];

        $trackers = $this->sections($after, $before)['trackers'];

        self::assertTrue($trackers['reordered']);
        self::assertSame([[], [], []], [$trackers['added'], $trackers['removed'], $trackers['changed']]);
    }

    #[Test]
    public function numericKeysStayStringsInAddedRemovedAndChanged(): void
    {
        $before = ['trackers' => [['key' => '1', 'name' => 'One', 'max' => 1], ['key' => '2', 'name' => 'Two']]];
        $after = ['trackers' => [['key' => '1', 'name' => 'One', 'max' => 2], ['key' => '3', 'name' => 'Three']]];

        $trackers = $this->sections($after, $before)['trackers'];

        self::assertSame([['key' => '3', 'name' => 'Three']], $trackers['added']);
        self::assertSame([['key' => '2', 'name' => 'Two']], $trackers['removed']);
        self::assertSame([['key' => '1', 'name' => 'One', 'previousName' => 'One', 'fields' => ['max']]], $trackers['changed']);
    }

    #[Test]
    public function releasesOfDifferentSchemaVersionsAreNotCompared(): void
    {
        self::assertNull(ReleaseDiff::of(['trackers' => []], 2, ['oracles' => []], 1));
    }

    #[Test]
    public function malformedContentComparesWithoutFailing(): void
    {
        $sections = ReleaseDiff::of(['trackers' => 'x', 'sheet' => ['fields' => 3]], 2, ['trackers' => [null, 5]], 2);

        self::assertNotNull($sections);
        self::assertSame([['key' => '', 'name' => null]], [...$sections['trackers']['removed'], ...$sections['trackers']['added']]);
    }

    /**
     * @param array<string, mixed> $after
     * @param array<string, mixed> $before
     *
     * @return array<string, array{added: list<array{key: string, name: string|null}>, removed: list<array{key: string, name: string|null}>, changed: list<array{key: string, name: string|null, previousName: string|null, fields: list<string>}>, reordered: bool}>
     */
    private function sections(array $after, array $before): array
    {
        $sections = ReleaseDiff::of($after, 2, $before, 2);
        self::assertNotNull($sections);

        return $sections;
    }
}
