<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\AddPlace;
use App\Play\Application\AddPlaceHandler;
use App\Play\Application\CampaignNotFound;
use App\Play\Application\ClosePlace;
use App\Play\Application\ClosePlaceHandler;
use App\Play\Application\EditPlace;
use App\Play\Application\EditPlaceHandler;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\PlaceView;
use App\Play\Application\ReopenPlace;
use App\Play\Application\ReopenPlaceHandler;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\CampaignLimitReached;
use App\Play\Domain\Campaign\Lists\InvalidPlace;
use App\Play\Domain\Campaign\Lists\ItemStatus;
use App\Play\Domain\Campaign\Lists\Place;
use App\Play\Domain\Campaign\Lists\PlaceAlreadyExists;
use App\Play\Domain\Campaign\Lists\PlaceId;
use App\Play\Domain\Campaign\Lists\TagNotApplicable;
use App\Play\Domain\Campaign\Lists\UnknownPlace;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\Flow\ItemKind;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Tag;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\Snapshots;
use App\Tests\Support\Play\UnreadablePublishedGameSystemReleases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddPlace::class)]
#[CoversClass(AddPlaceHandler::class)]
#[CoversClass(EditPlace::class)]
#[CoversClass(EditPlaceHandler::class)]
#[CoversClass(ClosePlace::class)]
#[CoversClass(ClosePlaceHandler::class)]
#[CoversClass(ReopenPlace::class)]
#[CoversClass(ReopenPlaceHandler::class)]
#[CoversClass(PlaceView::class)]
final class PlaceHandlersTest extends TestCase
{
    private InMemoryCampaignRepository $campaigns;
    private AddPlaceHandler $add;
    private EditPlaceHandler $edit;
    private ClosePlaceHandler $close;
    private ReopenPlaceHandler $reopen;

    protected function setUp(): void
    {
        $releases = new InMemoryPublishedGameSystemReleases();
        // Tags: "ally" for every kind, "rival" for NPCs only.
        $releases->add(new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], tags: [new Tag('ally', 'Ally'), new Tag('rival', 'Rival', appliesTo: [ItemKind::Npc])]));
        // A newer release with other Tags: the commands follow the pinned v1.
        $releases->add(new GameSystemSnapshot('heist', 'Heist, revised', 2, null, [], [], tags: [new Tag('boss', 'Boss')]));
        $this->campaigns = new InMemoryCampaignRepository();
        $this->campaigns->add(Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-31T09:00:00+00:00')));
        $owned = new OwnedCampaigns($this->campaigns);
        $this->add = new AddPlaceHandler($owned, $this->campaigns, $releases);
        $this->edit = new EditPlaceHandler($owned, $this->campaigns, $releases);
        $this->close = new ClosePlaceHandler($owned, $this->campaigns);
        $this->reopen = new ReopenPlaceHandler($owned, $this->campaigns);
    }

    #[Test]
    public function itAddsAnOpenPlace(): void
    {
        ($this->add)(new AddPlace('campaign-1', 'user-1', 'place-1', ' Saltmarsh ', ' Fishing town ', [], ['Town'], 2));

        $place = $this->place('place-1');
        self::assertSame(['Saltmarsh', 'Fishing town', [], ['Town'], 2, ItemStatus::Open], [$place?->name(), $place?->notes(), $place?->tags(), $place?->freeTags(), $place?->weight(), $place?->status()]);
    }

    #[Test]
    public function aReleaseTagOfAnotherKindOrAnUnknownOneIsRefusedAndNothingChanges(): void
    {
        foreach ([['rival', TagNotApplicable::class], ['boss', UnknownReleaseTag::class]] as [$tag, $exception]) {
            try {
                ($this->add)(new AddPlace('campaign-1', 'user-1', 'place-1', 'Saltmarsh', null, [$tag], [], 1));
                self::fail('The release Tag was accepted.');
            } catch (\DomainException $thrown) {
                self::assertInstanceOf($exception, $thrown);
            }
        }

        self::assertSame([], $this->campaign()->places());
    }

    #[Test]
    public function aReleaseTagThatAppliesToPlacesIsKept(): void
    {
        ($this->add)(new AddPlace('campaign-1', 'user-1', 'place-1', 'Saltmarsh', null, ['ally'], [], 1));

        self::assertSame(['ally'], $this->place('place-1')?->tags());
    }

    #[Test]
    public function anInvalidPlaceIsRefusedAndNothingChanges(): void
    {
        $this->expectException(InvalidPlace::class);

        try {
            ($this->add)(new AddPlace('campaign-1', 'user-1', 'place-1', 'Saltmarsh', null, [], [], 4));
        } finally {
            self::assertSame([], $this->campaign()->places());
        }
    }

    #[Test]
    public function aRepeatedIdIsRefusedAndThePlaceKept(): void
    {
        ($this->add)(new AddPlace('campaign-1', 'user-1', 'place-1', 'Saltmarsh', null, [], [], 1));

        try {
            ($this->add)(new AddPlace('campaign-1', 'user-1', 'place-1', 'Other', null, [], [], 1));
            self::fail('The repeated id was accepted.');
        } catch (PlaceAlreadyExists) {
            self::assertSame(['Saltmarsh'], array_map(static fn (Place $place): string => $place->name(), $this->campaign()->places()));
        }
    }

    #[Test]
    public function aFullCampaignRefusesAnotherPlace(): void
    {
        $campaign = $this->campaign();
        for ($i = 1; $i <= Campaign::MAX_PLACES; ++$i) {
            $campaign->addPlace(PlaceId::fromString('place-'.$i), 'Place '.$i, null, [], [], 1, Snapshots::withTags('heist', 'Heist', 1));
        }

        $this->campaigns->save($campaign);

        $this->expectException(CampaignLimitReached::class);

        ($this->add)(new AddPlace('campaign-1', 'user-1', 'place-501', 'One too many', null, [], [], 1));
    }

    #[Test]
    public function itReplacesTheEditableFieldsAndKeepsTheStatus(): void
    {
        ($this->add)(new AddPlace('campaign-1', 'user-1', 'place-1', 'Saltmarsh', 'Fishing', [], ['Town'], 2));
        ($this->close)(new ClosePlace('campaign-1', 'user-1', 'place-1'));

        ($this->edit)(new EditPlace('campaign-1', 'user-1', 'place-1', 'Saltmarsh Keep', null, [], [], 1));

        $place = $this->place('place-1');
        self::assertSame(['Saltmarsh Keep', null, [], 1, ItemStatus::Closed], [$place?->name(), $place?->notes(), $place?->freeTags(), $place?->weight(), $place?->status()]);
    }

    #[Test]
    public function aFailingEditChangesNothing(): void
    {
        ($this->add)(new AddPlace('campaign-1', 'user-1', 'place-1', 'Saltmarsh', null, [], [], 2));
        $before = $this->place('place-1');

        foreach ([
            new EditPlace('campaign-1', 'user-1', 'place-1', 'Renamed', null, ['rival'], [], 1),
            new EditPlace('campaign-1', 'user-1', 'place-1', 'Renamed', null, [], [], 0),
        ] as $command) {
            try {
                ($this->edit)($command);
                self::fail('The failing edit was accepted.');
            } catch (InvalidPlace|TagNotApplicable) {
            }
        }

        self::assertEquals($before, $this->place('place-1'));
    }

    #[Test]
    public function itClosesAndReopensAPlaceAndBothAreIdempotent(): void
    {
        ($this->add)(new AddPlace('campaign-1', 'user-1', 'place-1', 'Saltmarsh', null, [], [], 1));

        ($this->close)(new ClosePlace('campaign-1', 'user-1', 'place-1'));
        ($this->close)(new ClosePlace('campaign-1', 'user-1', 'place-1'));
        self::assertSame(ItemStatus::Closed, $this->place('place-1')?->status());

        ($this->reopen)(new ReopenPlace('campaign-1', 'user-1', 'place-1'));
        ($this->reopen)(new ReopenPlace('campaign-1', 'user-1', 'place-1'));
        self::assertSame(ItemStatus::Open, $this->place('place-1')->status());
    }

    #[Test]
    public function anUnknownPlaceIsRefusedByEveryCommandThatNamesOne(): void
    {
        foreach ([
            fn () => ($this->edit)(new EditPlace('campaign-1', 'user-1', 'missing', 'Saltmarsh', null, [], [], 1)),
            fn () => ($this->close)(new ClosePlace('campaign-1', 'user-1', 'missing')),
            fn () => ($this->reopen)(new ReopenPlace('campaign-1', 'user-1', 'missing')),
        ] as $command) {
            try {
                $command();
                self::fail('The unknown Place was accepted.');
            } catch (UnknownPlace $exception) {
                self::assertSame('Place "missing" not found.', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function anUnknownOrBlankPlaceIdIsRefusedBeforeAnUnreadableReleaseIsRead(): void
    {
        $handler = new EditPlaceHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, new UnreadablePublishedGameSystemReleases(GameSystemReleaseNotFound::for('heist', 1)));

        try {
            $handler(new EditPlace('campaign-1', 'user-1', 'missing', 'Saltmarsh', null, [], [], 1));
            self::fail('The unknown Place was accepted.');
        } catch (UnknownPlace) {
        }

        $this->expectException(InvalidPlace::class);
        $handler(new EditPlace('campaign-1', 'user-1', ' ', 'Saltmarsh', null, [], [], 1));
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFoundByEveryCommand(): void
    {
        ($this->add)(new AddPlace('campaign-1', 'user-1', 'place-1', 'Saltmarsh', null, [], [], 1));

        foreach ([
            fn () => ($this->add)(new AddPlace('campaign-1', 'user-2', 'place-2', 'Dunmere', null, [], [], 1)),
            fn () => ($this->edit)(new EditPlace('campaign-1', 'user-2', 'place-1', 'Renamed', null, [], [], 1)),
            fn () => ($this->close)(new ClosePlace('campaign-1', 'user-2', 'place-1')),
            fn () => ($this->reopen)(new ReopenPlace('campaign-1', 'user-2', 'place-1')),
        ] as $command) {
            try {
                $command();
                self::fail('Another player\'s campaign was changed.');
            } catch (CampaignNotFound) {
            }
        }

        self::assertEquals(Place::of(PlaceId::fromString('place-1'), 'Saltmarsh', null, [], [], 1), $this->place('place-1'));
    }

    #[Test]
    public function theViewCarriesEveryField(): void
    {
        ($this->add)(new AddPlace('campaign-1', 'user-1', 'place-1', 'Saltmarsh', 'Fishing', [], ['Town'], 3));
        $place = $this->place('place-1') ?? throw new \LogicException('The Place is stored.');

        $view = PlaceView::of($place);

        self::assertSame(['place-1', 'Saltmarsh', 'Fishing', [], ['Town'], 3, 'open'], [$view->id, $view->name, $view->notes, $view->tags, $view->freeTags, $view->weight, $view->status]);
    }

    private function campaign(): Campaign
    {
        return $this->campaigns->ofId(CampaignId::fromString('campaign-1')) ?? throw new \LogicException('The campaign is stored.');
    }

    private function place(string $id): ?Place
    {
        return $this->campaign()->place(PlaceId::fromString($id));
    }
}
