<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\Lists\InvalidPlace;
use App\Play\Domain\Campaign\Lists\ItemRules;
use App\Play\Domain\Campaign\Lists\ItemStatus;
use App\Play\Domain\Campaign\Lists\Place;
use App\Play\Domain\Campaign\Lists\PlaceAlreadyExists;
use App\Play\Domain\Campaign\Lists\PlaceId;
use App\Play\Domain\Campaign\Lists\TagNotApplicable;
use App\Play\Domain\Campaign\Lists\UnknownPlace;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Tag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A campaign keeps the player's Places: every rule fails closed, every command is all or nothing,
 * and open Places list first. Release Tags must apply to Places (ADR 0047).
 */
#[CoversClass(Campaign::class)]
#[CoversClass(Place::class)]
#[CoversClass(PlaceId::class)]
#[CoversClass(ItemRules::class)]
#[CoversClass(TagNotApplicable::class)]
#[CoversClass(CampaignLimitReached::class)]
final class CampaignPlacesTest extends TestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        $this->release = new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], tags: [new Tag('ally', 'Ally'), new Tag('rival', 'Rival', appliesTo: [ItemKind::Npc]), new Tag('near', 'Near', appliesTo: [ItemKind::Place])]);
    }

    #[Test]
    public function aNewCampaignHoldsNoPlaces(): void
    {
        $campaign = $this->campaign();

        self::assertSame([], $campaign->places());
        self::assertNull($campaign->place(PlaceId::fromString('place-1')));
    }

    #[Test]
    public function aPlaceIsAddedOpenWithTrimmedValuesAndThePlayersSpelling(): void
    {
        $campaign = $this->campaign();

        $place = $campaign->addPlace(PlaceId::fromString('place-1'), '  Saltmarsh  ', '  A fishing town ', [], ['  Town ', 'Harbour'], 2, $this->release);

        self::assertSame(['place-1', 'Saltmarsh', 'A fishing town', [], ['Town', 'Harbour'], 2, ItemStatus::Open], [$place->id()->toString(), $place->name(), $place->notes(), $place->tags(), $place->freeTags(), $place->weight(), $place->status()]);
        self::assertSame([$place], $campaign->places());
        self::assertSame($place, $campaign->place(PlaceId::fromString('place-1')));
    }

    #[Test]
    public function blankNotesReadAsNone(): void
    {
        $campaign = $this->campaign();

        $place = $campaign->addPlace(PlaceId::fromString('place-1'), 'Saltmarsh', " \n ", [], [], 1, $this->release);
        $none = $campaign->addPlace(PlaceId::fromString('place-2'), 'Dunmere', null, [], [], 1, $this->release);

        self::assertSame([null, null], [$place->notes(), $none->notes()]);
    }

    #[Test]
    public function aPlaceCarriesTheReleaseTagsThatApplyToPlaces(): void
    {
        $campaign = $this->campaign();

        $place = $campaign->addPlace(PlaceId::fromString('place-1'), 'Saltmarsh', null, ['ally', 'near'], [], 1, $this->release);
        $edited = $campaign->editPlace(PlaceId::fromString('place-1'), 'Saltmarsh', null, ['near'], [], 1, $this->release);

        self::assertSame([['ally', 'near'], ['near']], [$place->tags(), $edited->tags()]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, class-string<\Throwable>, string}>
     */
    public static function invalidPlaces(): iterable
    {
        yield 'blank name' => [['name' => " \t"], InvalidPlace::class, 'The name must not be blank.'];
        yield 'name over 200 characters' => [['name' => str_repeat('a', 201)], InvalidPlace::class, 'The name must be at most 200 characters, got 201.'];
        yield 'notes over 2000 characters' => [['notes' => str_repeat('a', 2001)], InvalidPlace::class, 'The notes must be at most 2000 characters, got 2001.'];
        yield 'weight out of range' => [['weight' => 4], InvalidPlace::class, 'The weight must be 1 to 3, got 4.'];
        yield 'repeated free tag' => [['freeTags' => ['Town', 'town']], InvalidPlace::class, 'Free tag "town" is listed twice.'];
        yield 'release Tag limited to other kinds' => [['tags' => ['rival']], TagNotApplicable::class, 'Tag "rival" does not apply to Places.'];
        yield 'release Tag the release does not define' => [['tags' => ['boss']], UnknownReleaseTag::class, 'Tag "boss" not found in the pinned release.'];
    }

    /**
     * @param array<string, mixed>     $with
     * @param class-string<\Throwable> $exception
     */
    #[Test]
    #[DataProvider('invalidPlaces')]
    public function anInvalidPlaceIsRefusedWithAClearErrorAndNothingChanges(array $with, string $exception, string $message): void
    {
        $campaign = $this->campaign();

        try {
            /** @var array{name: string, notes: ?string, tags: list<string>, freeTags: list<string>, weight: int} $values */
            $values = [...['name' => 'Saltmarsh', 'notes' => null, 'tags' => [], 'freeTags' => [], 'weight' => 1], ...$with];
            $campaign->addPlace(PlaceId::fromString('place-1'), $values['name'], $values['notes'], $values['tags'], $values['freeTags'], $values['weight'], $this->release);
            self::fail('The invalid Place was accepted.');
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($exception, $thrown);
            self::assertSame($message, $thrown->getMessage());
        }

        self::assertSame([], $campaign->places());
    }

    #[Test]
    public function aBlankIdIsRefused(): void
    {
        $this->expectException(InvalidPlace::class);

        PlaceId::fromString(' ');
    }

    #[Test]
    public function aPlaceIsEditedKeepingItsIdAndStatus(): void
    {
        $campaign = $this->campaign();
        $campaign->addPlace(PlaceId::fromString('place-1'), 'Saltmarsh', 'Fishing', [], ['Town'], 2, $this->release);
        $campaign->closePlace(PlaceId::fromString('place-1'));

        $edited = $campaign->editPlace(PlaceId::fromString('place-1'), 'Saltmarsh Keep', null, [], [], 3, $this->release);

        self::assertSame(['place-1', 'Saltmarsh Keep', null, [], 3, ItemStatus::Closed], [$edited->id()->toString(), $edited->name(), $edited->notes(), $edited->freeTags(), $edited->weight(), $edited->status()]);
        self::assertSame([$edited], $campaign->places());
    }

    #[Test]
    public function aFailingEditChangesNothing(): void
    {
        $campaign = $this->campaign();
        $campaign->addPlace(PlaceId::fromString('place-1'), 'Saltmarsh', 'Fishing', [], ['Town'], 2, $this->release);
        $before = $campaign->places();

        foreach ([
            static fn (Campaign $c, GameSystemSnapshot $r): mixed => $c->editPlace(PlaceId::fromString('place-1'), 'Renamed', null, ['rival'], [], 1, $r),
            static fn (Campaign $c, GameSystemSnapshot $r): mixed => $c->editPlace(PlaceId::fromString('place-1'), 'Renamed', null, [], ['a', 'A'], 1, $r),
            static fn (Campaign $c, GameSystemSnapshot $r): mixed => $c->editPlace(PlaceId::fromString('place-1'), 'Renamed', str_repeat('a', 2001), [], [], 1, $r),
        ] as $edit) {
            try {
                $edit($campaign, $this->release);
                self::fail('The failing edit was accepted.');
            } catch (InvalidPlace|TagNotApplicable) {
            }
        }

        self::assertEquals($before, $campaign->places());
    }

    #[Test]
    public function aPlaceIsClosedAndReopenedAndBothAreIdempotent(): void
    {
        $campaign = $this->campaign();
        $campaign->addPlace(PlaceId::fromString('place-1'), 'Saltmarsh', null, [], [], 1, $this->release);

        $campaign->closePlace(PlaceId::fromString('place-1'));
        $campaign->closePlace(PlaceId::fromString('place-1'));
        self::assertSame(ItemStatus::Closed, $campaign->place(PlaceId::fromString('place-1'))?->status());

        $campaign->reopenPlace(PlaceId::fromString('place-1'));
        $campaign->reopenPlace(PlaceId::fromString('place-1'));
        self::assertSame(ItemStatus::Open, $campaign->place(PlaceId::fromString('place-1'))?->status());
    }

    #[Test]
    public function openPlacesListFirstEachInCreationOrder(): void
    {
        $campaign = $this->campaign();
        foreach (['a', 'b', 'c'] as $id) {
            $campaign->addPlace(PlaceId::fromString($id), strtoupper($id), null, [], [], 1, $this->release);
        }

        $campaign->closePlace(PlaceId::fromString('a'));

        self::assertSame(['B', 'C', 'A'], array_map(static fn (Place $place): string => $place->name(), $campaign->places()));
    }

    #[Test]
    public function aPlaceCreatedByAnEffectIsOpenNamedTaggedAndReturned(): void
    {
        $campaign = $this->campaign();

        $place = $campaign->createPlaceByEffect('The sunken chapel', ['near'], $this->release);

        self::assertInstanceOf(Place::class, $place);
        self::assertSame(['The sunken chapel', ['near'], [], null, ItemRules::DEFAULT_WEIGHT, ItemStatus::Open], [$place->name(), $place->tags(), $place->freeTags(), $place->notes(), $place->weight(), $place->status()]);
        self::assertSame([$place], $campaign->places());
    }

    #[Test]
    public function placesCreatedByEffectsGetDistinctGeneratedIds(): void
    {
        $campaign = $this->campaign();

        $first = $campaign->createPlaceByEffect('Saltmarsh', [], $this->release);
        $second = $campaign->createPlaceByEffect('Dunmere', [], $this->release);

        self::assertNotSame($first?->id()->toString(), $second?->id()->toString());
        self::assertCount(2, $campaign->places());
    }

    #[Test]
    public function aPlaceIsNotCreatedByAnEffectAtTheLimit(): void
    {
        $campaign = $this->campaign();
        for ($i = 1; $i <= Campaign::MAX_PLACES; ++$i) {
            $campaign->addPlace(PlaceId::fromString('place-'.$i), 'Place '.$i, null, [], [], 1, $this->release);
        }

        self::assertNull($campaign->createPlaceByEffect('One too many', [], $this->release));
        self::assertCount(Campaign::MAX_PLACES, $campaign->places());
    }

    #[Test]
    public function aPlaceEffectWithATagThatDoesNotApplyCreatesNothing(): void
    {
        $campaign = $this->campaign();

        try {
            $campaign->createPlaceByEffect('Saltmarsh', ['rival'], $this->release);
            self::fail('The Tag limited to NPCs was accepted.');
        } catch (TagNotApplicable $exception) {
            self::assertSame('Tag "rival" does not apply to Places.', $exception->getMessage());
        }

        self::assertSame([], $campaign->places());
    }

    #[Test]
    public function aPlaceEffectIsRefusedForAReleaseThatIsNotThePinnedOne(): void
    {
        $campaign = $this->campaign();
        $other = new GameSystemSnapshot('heist', 'Heist', 2, null, [], []);

        try {
            $campaign->createPlaceByEffect('Saltmarsh', [], $other);
            self::fail('The release that is not pinned was accepted.');
        } catch (\LogicException $exception) {
            self::assertSame('The campaign works with its pinned release.', $exception->getMessage());
        }

        self::assertSame([], $campaign->places());
    }

    #[Test]
    public function anUnknownPlaceIsRefusedByEveryCommandThatNamesOne(): void
    {
        $campaign = $this->campaign();

        foreach ([
            static fn (Campaign $c, GameSystemSnapshot $r): mixed => $c->editPlace(PlaceId::fromString('missing'), 'Saltmarsh', null, [], [], 1, $r),
            static function (Campaign $c): void {
                $c->closePlace(PlaceId::fromString('missing'));
            },
            static function (Campaign $c): void {
                $c->reopenPlace(PlaceId::fromString('missing'));
            },
        ] as $command) {
            try {
                $command($campaign, $this->release);
                self::fail('The unknown Place was accepted.');
            } catch (UnknownPlace $exception) {
                self::assertSame('Place "missing" not found.', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function aRepeatedIdIsRefusedAndThePlaceKept(): void
    {
        $campaign = $this->campaign();
        $campaign->addPlace(PlaceId::fromString('same'), 'Saltmarsh', null, [], [], 1, $this->release);

        try {
            $campaign->addPlace(PlaceId::fromString('same'), 'Other', null, [], [], 1, $this->release);
            self::fail('The duplicate Place id was accepted.');
        } catch (PlaceAlreadyExists $exception) {
            self::assertSame('Place "same" already exists.', $exception->getMessage());
        }

        self::assertSame(['Saltmarsh'], array_map(static fn (Place $place): string => $place->name(), $campaign->places()));
    }

    #[Test]
    public function aCampaignHoldsAtMost500Places(): void
    {
        $campaign = $this->campaign();
        for ($i = 1; $i <= Campaign::MAX_PLACES; ++$i) {
            $campaign->addPlace(PlaceId::fromString('place-'.$i), 'Place '.$i, null, [], [], 1, $this->release);
        }

        try {
            $campaign->addPlace(PlaceId::fromString('place-501'), 'One too many', null, [], [], 1, $this->release);
            self::fail('The 501st Place was accepted.');
        } catch (CampaignLimitReached $exception) {
            self::assertSame('A campaign holds at most 500 Places.', $exception->getMessage());
        }

        self::assertCount(500, $campaign->places());
    }

    #[Test]
    public function aChangeReplacesTheListAndThePlaceSoStoredValuesCompareDifferent(): void
    {
        $campaign = $this->campaign();
        $campaign->addPlace(PlaceId::fromString('place-1'), 'Saltmarsh', null, [], [], 1, $this->release);
        $before = $campaign->places();

        $campaign->closePlace(PlaceId::fromString('place-1'));

        self::assertNotSame($before, $campaign->places());
        self::assertNotSame($before[0], $campaign->places()[0]);
        self::assertSame(ItemStatus::Open, $before[0]->status());
    }

    #[Test]
    public function placesAreRebuiltByReconstituteAsStored(): void
    {
        $place = Place::of(PlaceId::fromString('place-1'), 'Saltmarsh', 'Fishing', [], ['Town'], 3, ItemStatus::Closed);

        $campaign = $this->rebuild([$place]);

        self::assertSame([$place], $campaign->places());
    }

    #[Test]
    public function reconstituteRefusesTwoPlacesOfTheSameId(): void
    {
        $place = static fn (string $name): Place => Place::of(PlaceId::fromString('same'), $name, null, [], [], 1);

        $this->expectException(PlaceAlreadyExists::class);
        $this->expectExceptionMessageIsOrContains('Place "same" already exists.');

        $this->rebuild([$place('Saltmarsh'), $place('Dunmere')]);
    }

    /**
     * @param list<Place> $places
     */
    private function rebuild(array $places): Campaign
    {
        return Campaign::reconstitute(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable(), [], places: $places);
    }

    private function campaign(): Campaign
    {
        return Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-31T09:00:00+00:00'));
    }
}
