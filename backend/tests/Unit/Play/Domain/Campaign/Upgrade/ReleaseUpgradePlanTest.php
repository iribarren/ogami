<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\Upgrade;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Facts\FactFill;
use App\Play\Domain\Campaign\Facts\FactId;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\ThreadId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\Campaign\Upgrade\CampaignUpToDate;
use App\Play\Domain\Campaign\Upgrade\FieldChange;
use App\Play\Domain\Campaign\Upgrade\FieldChangeKind;
use App\Play\Domain\Campaign\Upgrade\ReleaseUpgradePlan;
use App\Play\Domain\Campaign\Upgrade\ReleaseUpgradePlanner;
use App\Play\Domain\Campaign\Upgrade\TrackerChangeKind;
use App\Play\Domain\Campaign\Upgrade\UpgradeToAnotherGameSystem;
use App\Play\Domain\GameSystem\FactSlot;
use App\Play\Domain\GameSystem\FactSlotType;
use App\Play\Domain\GameSystem\Sheet\DerivedField;
use App\Play\Domain\GameSystem\Sheet\Field;
use App\Play\Domain\GameSystem\Sheet\ListField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\TextField;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Play\Domain\GameSystem\Tracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The upgrade plan lists what moving a campaign to a newer release changes (ADR 0035): one rule per
 * kind of stored value, in a deterministic order, with a fingerprint that stands for exactly that.
 */
#[CoversClass(ReleaseUpgradePlan::class)]
#[CoversClass(ReleaseUpgradePlanner::class)]
#[CoversClass(FieldChange::class)]
#[CoversClass(Campaign::class)]
final class ReleaseUpgradePlanTest extends TestCase
{
    /**
     * @return iterable<string, array{Field, Field, mixed, ?FieldChangeKind, mixed}>
     */
    public static function fieldRules(): iterable
    {
        yield 'a valid number is kept' => [new NumberField('f', 'F', min: 1, max: 10), new NumberField('f', 'F', min: 1, max: 10), 5, null, 5];
        yield 'a number above the new max is clamped' => [new NumberField('f', 'F', max: 10), new NumberField('f', 'F', max: 3), 8, FieldChangeKind::Clamped, 3];
        yield 'a number below the new min is clamped' => [new NumberField('f', 'F', min: 1), new NumberField('f', 'F', min: 5), 2, FieldChangeKind::Clamped, 5];
        yield 'a track above a new fixed max is clamped' => [new TrackField('f', 'F', max: 40), new TrackField('f', 'F', max: 10), ['current' => 40, 'max' => 40], FieldChangeKind::Clamped, ['current' => 10, 'max' => 10]];
        yield 'a track of a fixed max that now grows is clamped to the new max' => [new TrackField('f', 'F', max: 4), new TrackField('f', 'F', max: 6), ['current' => 3, 'max' => 4], FieldChangeKind::Clamped, ['current' => 3, 'max' => 6]];
        yield 'a track of its own max is kept' => [new TrackField('f', 'F'), new TrackField('f', 'F'), ['current' => 3, 'max' => 5], null, ['current' => 3, 'max' => 5]];
        yield 'a track that gets a fixed max is clamped' => [new TrackField('f', 'F'), new TrackField('f', 'F', max: 4), ['current' => 5, 'max' => 5], FieldChangeKind::Clamped, ['current' => 4, 'max' => 4]];
        yield 'a list within its limit is kept' => [new ListField('f', 'F', maxItems: 3), new ListField('f', 'F', maxItems: 2), ['a', 'b'], null, ['a', 'b']];
        yield 'a list over the new limit keeps its first items' => [new ListField('f', 'F', maxItems: 3), new ListField('f', 'F', maxItems: 2), ['a', 'b', 'c'], FieldChangeKind::Trimmed, ['a', 'b']];
        yield 'a text within its limit is kept' => [new TextField('f', 'F'), new TextField('f', 'F', maxLength: 5), 'short', null, 'short'];
        yield 'a text over the new limit is reset' => [new TextField('f', 'F'), new TextField('f', 'F', maxLength: 5), 'too long', FieldChangeKind::Reset, ''];
        yield 'a text with a line break in a single-line field is reset' => [new TextField('f', 'F', multiline: true), new TextField('f', 'F'), "a\nb", FieldChangeKind::Reset, ''];
        yield 'a number that became a text is reset' => [new NumberField('f', 'F'), new TextField('f', 'F'), 5, FieldChangeKind::Reset, ''];
        yield 'a text that became a list is reset' => [new TextField('f', 'F'), new ListField('f', 'F'), 'abc', FieldChangeKind::Reset, []];
        yield 'a list that became a track is reset to the track start' => [new ListField('f', 'F'), new TrackField('f', 'F', max: 7), ['a'], FieldChangeKind::Reset, ['current' => 7, 'max' => 7]];
        yield 'a number that became a number with a default is kept' => [new NumberField('f', 'F'), new NumberField('f', 'F', default: 9), 2, null, 2];
    }

    #[Test]
    #[DataProvider('fieldRules')]
    public function aStoredValueIsFittedToTheNewField(Field $old, Field $new, mixed $value, ?FieldChangeKind $kind, mixed $expected): void
    {
        $pinned = UpgradeFixtures::release(1, [$old]);
        $campaign = UpgradeFixtures::campaign($pinned);
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], ['f' => $value], $pinned);

        $plan = $campaign->planReleaseUpgrade($pinned, UpgradeFixtures::release(2, [$new]));

        if (!$kind instanceof FieldChangeKind) {
            self::assertSame([], $plan->characters);

            return;
        }

        self::assertCount(1, $plan->characters);
        $change = $plan->characters[0]->changes[0];
        self::assertSame(['f', 'F', $kind, $value, $expected], [$change->fieldKey, $change->fieldName, $change->kind, $change->before, $change->after]);
        self::assertCount(1, $plan->characters[0]->changes);
    }

    #[Test]
    public function aNewStoredFieldIsAddedWithItsInitialValue(): void
    {
        $pinned = UpgradeFixtures::release(1, [new NumberField('luck', 'Luck')]);
        $campaign = UpgradeFixtures::campaign($pinned);
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], ['luck' => 2], $pinned);

        $plan = $campaign->planReleaseUpgrade($pinned, UpgradeFixtures::release(2, [new NumberField('luck', 'Luck'), new NumberField('nerve', 'Nerve', min: 2, default: 5), new TrackField('hp', 'Hit points', max: 8)]));

        self::assertEquals(
            [
                new FieldChange('nerve', 'Nerve', FieldChangeKind::Added, null, 5),
                new FieldChange('hp', 'Hit points', FieldChangeKind::Added, null, ['current' => 8, 'max' => 8]),
            ],
            $plan->characters[0]->changes,
        );
    }

    #[Test]
    public function aGoneFieldAndAFieldThatBecameDerivedLoseTheirValuesAndNeverAppearAsDerived(): void
    {
        $pinned = UpgradeFixtures::release(1, [new NumberField('luck', 'Luck'), new NumberField('dodge', 'Dodge'), new TextField('role', 'Role'), new NumberField('edge', 'Edge')]);
        $campaign = UpgradeFixtures::campaign($pinned);
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], ['luck' => 2, 'dodge' => 4, 'role' => 'Fixer', 'edge' => 1], $pinned);

        $target = UpgradeFixtures::release(2, [new NumberField('luck', 'Luck'), new DerivedField('dodge', 'Dodge', null, null, Formula::fromString('{luck} * 2')), new DerivedField('grit', 'Grit', null, null, Formula::fromString('{luck} + 1'))]);
        $plan = $campaign->planReleaseUpgrade($pinned, $target);

        self::assertEquals(
            [
                new FieldChange('dodge', 'Dodge', FieldChangeKind::Removed, 4, null),
                new FieldChange('role', 'Role', FieldChangeKind::Removed, 'Fixer', null),
                new FieldChange('edge', 'Edge', FieldChangeKind::Removed, 1, null),
            ],
            $plan->characters[0]->changes,
        );
    }

    #[Test]
    public function changesFollowTheNewSheetThenTheRemovedFieldsInTheOldOrderAndCharactersTheCampaignOrder(): void
    {
        $pinned = UpgradeFixtures::release(1, [new TextField('a', 'A'), new TextField('b', 'B'), new TextField('c', 'C')]);
        $campaign = UpgradeFixtures::campaign($pinned);
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Zed', [], [], [], $pinned);
        $campaign->addCharacter(CharacterId::fromString('c-2'), 'Ana', [], [], [], $pinned);

        $plan = $campaign->planReleaseUpgrade($pinned, UpgradeFixtures::release(2, [new NumberField('z', 'Z'), new TextField('c', 'C', maxLength: 5)]));

        self::assertSame(['c-1', 'c-2'], array_map(static fn (\App\Play\Domain\Campaign\Upgrade\CharacterUpgrade $character): string => $character->characterId, $plan->characters));
        self::assertSame(['z', 'a', 'b'], array_map(static fn (FieldChange $change): string => $change->fieldKey, $plan->characters[0]->changes));
    }

    #[Test]
    public function aCharacterWithNothingToChangeIsNotListed(): void
    {
        $pinned = UpgradeFixtures::release(1, [new TextField('role', 'Role')], ['ally']);
        $campaign = UpgradeFixtures::campaign($pinned);
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', ['ally'], ['free'], ['role' => 'Fixer'], $pinned);

        $plan = $campaign->planReleaseUpgrade($pinned, UpgradeFixtures::release(2, [new TextField('role', 'Role')], ['ally', 'rival']));

        self::assertSame([], $plan->characters);
        self::assertSame([], $plan->npcs);
        self::assertSame([], $plan->threads);
        self::assertSame([], $plan->trackers);
        self::assertSame([], $plan->facts);
    }

    #[Test]
    public function tagsThatLeaveTheReleaseAreDroppedFromCharactersNpcsAndThreadsButFreeTagsStay(): void
    {
        $pinned = UpgradeFixtures::release(1, [], ['ally', 'rival', 'boss']);
        $campaign = UpgradeFixtures::campaign($pinned);
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', ['boss', 'ally', 'rival'], ['boss'], [], $pinned);
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, ['rival', 'ally'], [], 1, $pinned);
        $campaign->addNpc(NpcId::fromString('npc-2'), 'Dax', null, ['ally'], [], 1, $pinned);
        $campaign->addThread(ThreadId::fromString('thread-1'), 'Vault', ['boss', 'rival'], [], 1, $pinned);

        $plan = $campaign->planReleaseUpgrade($pinned, UpgradeFixtures::release(2, [], ['ally']));

        self::assertSame(['boss', 'rival'], $plan->characters[0]->droppedTags);
        self::assertSame([], $plan->characters[0]->changes);
        self::assertSame([['npc-1', 'Mira', ['rival']]], array_map(static fn (\App\Play\Domain\Campaign\Upgrade\DroppedTags $npc): array => [$npc->id, $npc->name, $npc->tags], $plan->npcs));
        self::assertSame([['thread-1', 'Vault', ['boss', 'rival']]], array_map(static fn (\App\Play\Domain\Campaign\Upgrade\DroppedTags $thread): array => [$thread->id, $thread->name, $thread->tags], $plan->threads));
    }

    #[Test]
    public function trackerValuesAreRemovedWhenTheTrackerIsGoneAndClampedToTheNewBounds(): void
    {
        $heat = Tracker::counter('heat', 'Heat', null, 0, 10, 2);
        $alarm = Tracker::clock('alarm', 'Alarm', null, 6);
        $cash = Tracker::counter('cash', 'Cash', null, 0, 10, 5);
        $luck = Tracker::counter('luck', 'Luck', null, 0, 10, 5);
        $pinned = UpgradeFixtures::release(1, trackers: [$heat, $alarm, $cash, $luck]);
        $campaign = UpgradeFixtures::campaign($pinned, trackers: [$heat, $alarm, $cash, $luck]);
        $campaign->setTrackerValue($heat, 9);
        $campaign->setTrackerValue($alarm, 5);
        $campaign->setTrackerValue($cash, 4);

        $target = UpgradeFixtures::release(2, trackers: [Tracker::counter('heat', 'Heat', null, 0, 6, 2), Tracker::clock('alarm', 'Alarm', null, 4), $cash, Tracker::counter('fame', 'Fame', null, 0, 5, 1)]);
        $plan = $campaign->planReleaseUpgrade($pinned, $target);

        self::assertSame(
            [['alarm', TrackerChangeKind::Clamped, 5, 4], ['heat', TrackerChangeKind::Clamped, 9, 6], ['luck', TrackerChangeKind::Removed, 5, null]],
            array_map(static fn (\App\Play\Domain\Campaign\Upgrade\TrackerChange $change): array => [$change->trackerKey, $change->kind, $change->before, $change->after], $plan->trackers),
        );
    }

    #[Test]
    public function aFactWhoseSlotIsGoneBecomesAFreeFactKeepingItsLabelOrTheOldSlotName(): void
    {
        $slots = [new FactSlot('city', 'City', FactSlotType::Text), new FactSlot('prince', 'The Prince', FactSlotType::Npc), new FactSlot('goal', 'Goal', FactSlotType::Text)];
        $pinned = UpgradeFixtures::release(1, factSlots: $slots);
        $campaign = UpgradeFixtures::campaign($pinned);
        $campaign->fillFactSlot(FactId::fromString('fact-1'), 'city', FactFill::text('Night City'), $pinned);
        $campaign->fillFactSlot(FactId::fromString('fact-2'), 'prince', FactFill::newNpc(NpcId::fromString('npc-1'), 'Mithras'), $pinned);
        $campaign->fillFactSlot(FactId::fromString('fact-3'), 'goal', FactFill::text('Rob the vault'), $pinned);
        $campaign->addFreeFact(FactId::fromString('fact-4'), 'Rumour', 'Free', [], $pinned);

        $plan = $campaign->planReleaseUpgrade($pinned, UpgradeFixtures::release(2, factSlots: [$slots[2]]));

        self::assertSame(
            [['fact-1', 'city', 'City', 'Night City'], ['fact-2', 'prince', 'The Prince', 'Mithras']],
            array_map(static fn (\App\Play\Domain\Campaign\Upgrade\UnslottedFact $fact): array => [$fact->factId, $fact->slotKey, $fact->label, $fact->text], $plan->facts),
        );
    }

    /**
     * @return iterable<string, array{?string, list<string>, list<string>, ?string, bool}> the campaign's Flow, the target's Flows (a * marks the default), the Flow after, restarted
     */
    public static function flows(): iterable
    {
        yield 'the same Flow when the target has it' => ['quick', ['long', 'quick*'], ['quick'], 'quick', true];
        yield 'the default Flow when the Flow is gone' => ['quick', ['long', 'main*'], ['quick'], 'main', true];
        yield 'the first Flow when none is the default' => ['quick', ['long', 'main'], ['quick'], 'long', true];
        yield 'free play when the target has no Flows' => ['quick', [], ['quick'], null, true];
        yield 'free play stays free' => [null, ['long*'], [], null, false];
    }

    /**
     * @param list<string> $targetFlows
     * @param list<string> $pinnedFlows
     */
    #[Test]
    #[DataProvider('flows')]
    public function theFlowIsChosenByKeyThenDefaultThenFirstAndGuidanceRestarts(?string $flowKey, array $targetFlows, array $pinnedFlows, ?string $expected, bool $restarted): void
    {
        $pinned = UpgradeFixtures::release(1, flows: array_map(static fn (string $key): \App\Play\Domain\GameSystem\Flow\Flow => UpgradeFixtures::flow($key), $pinnedFlows));
        $campaign = UpgradeFixtures::campaign($pinned, null === $flowKey ? null : UpgradeFixtures::flow($flowKey));
        $target = UpgradeFixtures::release(2, flows: array_map(static fn (string $key): \App\Play\Domain\GameSystem\Flow\Flow => UpgradeFixtures::flow(rtrim($key, '*'), str_ends_with($key, '*')), $targetFlows));

        $flow = $campaign->planReleaseUpgrade($pinned, $target)->flow;

        self::assertSame([$flowKey, $expected, $restarted], [$flow->fromFlowKey, $flow->toFlowKey, $flow->restarted]);
    }

    #[Test]
    public function aVersionOneReleaseUpgradesToAVersionTwoRelease(): void
    {
        $pinned = UpgradeFixtures::bare();
        $campaign = UpgradeFixtures::campaign($pinned);
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], [], $pinned);
        $target = UpgradeFixtures::release(2, [new NumberField('luck', 'Luck')], ['ally'], [Tracker::counter('heat', 'Heat', null, 0, 5, 1)], [], [UpgradeFixtures::flow('main', true)]);

        $plan = $campaign->planReleaseUpgrade($pinned, $target);

        self::assertSame([1, 2], [$plan->from->releaseVersion(), $plan->to->releaseVersion()]);
        self::assertSame([FieldChangeKind::Added], array_map(static fn (FieldChange $change): FieldChangeKind => $change->kind, $plan->characters[0]->changes));
        self::assertSame([], $plan->trackers);
        self::assertNull($plan->flow->toFlowKey);
    }

    #[Test]
    public function aCampaignWithoutCharactersOrGuidanceHasAnEmptyPlan(): void
    {
        $pinned = UpgradeFixtures::bare();

        $plan = UpgradeFixtures::campaign($pinned)->planReleaseUpgrade($pinned, UpgradeFixtures::release(3));

        self::assertSame([], [...$plan->characters, ...$plan->npcs, ...$plan->threads, ...$plan->trackers, ...$plan->facts]);
        self::assertFalse($plan->flow->restarted);
    }

    #[Test]
    public function theFingerprintIsStableAndChangesWithEveryInputOfThePlan(): void
    {
        $pinned = UpgradeFixtures::release(1, [new NumberField('luck', 'Luck', max: 10)], ['ally']);
        $campaign = UpgradeFixtures::campaign($pinned);
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', ['ally'], [], ['luck' => 8], $pinned);
        $target = UpgradeFixtures::release(2, [new NumberField('luck', 'Luck', max: 5)]);

        $fingerprint = $campaign->planReleaseUpgrade($pinned, $target)->fingerprint();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        self::assertSame($fingerprint, $campaign->planReleaseUpgrade($pinned, $target)->fingerprint());
        self::assertNotSame($fingerprint, $campaign->planReleaseUpgrade($pinned, UpgradeFixtures::release(3, [new NumberField('luck', 'Luck', max: 5)]))->fingerprint(), 'another target version');
        self::assertNotSame($fingerprint, $campaign->planReleaseUpgrade($pinned, UpgradeFixtures::release(2, [new NumberField('luck', 'Luck', max: 4)]))->fingerprint(), 'another clamped value');
        self::assertNotSame($fingerprint, $campaign->planReleaseUpgrade($pinned, UpgradeFixtures::release(2, [new NumberField('luck', 'Luck', max: 5)], ['ally']))->fingerprint(), 'a Tag that stays');

        $campaign->editCharacter(CharacterId::fromString('c-1'), 'Vi', ['ally'], [], ['luck' => 7], $pinned);
        self::assertNotSame($fingerprint, $campaign->planReleaseUpgrade($pinned, $target)->fingerprint(), 'an edited campaign');
    }

    #[Test]
    public function theFingerprintDoesNotDependOnTheKeyOrderOfStoredTracks(): void
    {
        $pinned = UpgradeFixtures::release(1, [new TrackField('hp', 'Hit points')]);
        $target = UpgradeFixtures::release(2, [new TrackField('hp', 'Hit points', max: 3)]);
        $campaign = UpgradeFixtures::campaign($pinned);
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], ['hp' => ['current' => 5, 'max' => 5]], $pinned);
        $reordered = UpgradeFixtures::campaign($pinned);
        $reordered->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], ['hp' => ['max' => 5, 'current' => 5]], $pinned);

        self::assertSame($campaign->planReleaseUpgrade($pinned, $target)->fingerprint(), $reordered->planReleaseUpgrade($pinned, $target)->fingerprint());
    }

    #[Test]
    public function aTrackWhoseStoredKeysCameBackReorderedAndIsUnchangedIsNotAChange(): void
    {
        $pinned = UpgradeFixtures::release(1, [new TrackField('hp', 'Hit points')]);
        $target = UpgradeFixtures::release(2, [new TrackField('hp', 'Hit points', max: 5)]);
        $campaign = UpgradeFixtures::campaign($pinned);
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], ['hp' => ['current' => 4, 'max' => 5]], $pinned);
        $reordered = Campaign::reconstitute(
            CampaignId::fromString('campaign-1'),
            'user-1',
            'The job',
            PinnedRelease::of('heist', 1, 'Heist'),
            new \DateTimeImmutable('2026-10-12T09:00:00+00:00'),
            [],
            [],
            null,
            null,
            [],
            [],
            [],
            [Character::of(CharacterId::fromString('c-1'), 'Vi', [], [], ['hp' => ['max' => 5, 'current' => 4]])],
        );

        self::assertSame([], $reordered->planReleaseUpgrade($pinned, $target)->characters);
        self::assertSame($campaign->planReleaseUpgrade($pinned, $target)->fingerprint(), $reordered->planReleaseUpgrade($pinned, $target)->fingerprint());
    }

    #[Test]
    public function aTargetThatIsNotNewerIsRefused(): void
    {
        $pinned = UpgradeFixtures::release(2);
        $campaign = UpgradeFixtures::campaign($pinned);

        $this->expectException(CampaignUpToDate::class);

        $campaign->planReleaseUpgrade($pinned, UpgradeFixtures::release(2));
    }

    #[Test]
    public function anOlderTargetIsRefused(): void
    {
        $pinned = UpgradeFixtures::release(3);

        $this->expectException(CampaignUpToDate::class);

        UpgradeFixtures::campaign($pinned)->planReleaseUpgrade($pinned, UpgradeFixtures::release(1));
    }

    #[Test]
    public function aReleaseOfAnotherGameSystemIsRefused(): void
    {
        $pinned = UpgradeFixtures::release(1);

        $this->expectException(UpgradeToAnotherGameSystem::class);

        UpgradeFixtures::campaign($pinned)->planReleaseUpgrade($pinned, UpgradeFixtures::release(2, key: 'noir'));
    }

    #[Test]
    public function aPinnedSnapshotThatIsNotThePinnedReleaseIsRefused(): void
    {
        $campaign = UpgradeFixtures::campaign(UpgradeFixtures::release(1));

        $this->expectException(\LogicException::class);

        $campaign->planReleaseUpgrade(UpgradeFixtures::release(2), UpgradeFixtures::release(3));
    }
}
