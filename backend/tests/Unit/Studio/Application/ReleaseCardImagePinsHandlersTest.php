<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\CardImageNotFound;
use App\Studio\Application\CardImages;
use App\Studio\Application\CreateGameSystem;
use App\Studio\Application\CreateGameSystemHandler;
use App\Studio\Application\GameSystemReleaseContentView;
use App\Studio\Application\GetCardImage;
use App\Studio\Application\GetCardImageHandler;
use App\Studio\Application\GetGameSystemReleaseContent;
use App\Studio\Application\GetGameSystemReleaseContentHandler;
use App\Studio\Application\OwnedGameSystems;
use App\Studio\Application\PublishGameSystemDraft;
use App\Studio\Application\PublishGameSystemDraftHandler;
use App\Studio\Application\ReleasePublisher;
use App\Studio\Application\RestoreGameSystemRelease;
use App\Studio\Application\RestoreGameSystemReleaseHandler;
use App\Studio\Application\StoredImage;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Image\CardImage;
use App\Studio\Domain\Image\CardImagePin;
use App\Studio\Domain\Image\CardImageSlot;
use App\Studio\Domain\Release\GameSystemDraftUnchanged;
use App\Studio\Domain\Release\GameSystemReleaseNotFound;
use App\Studio\Domain\Release\ReleaseId;
use App\Tests\Support\Studio\FixedClock;
use App\Tests\Support\Studio\InMemoryCardImages;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use App\Tests\Support\Studio\InMemoryGameSystemRepository;
use App\Tests\Support\Studio\SequentialGameSystemIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a release does with the card images of its draft (ADR 0053): pins them once at publish,
 * counts them as a change, keeps them readable, gives them back on restore and shows them to its owner.
 */
#[CoversClass(PublishGameSystemDraftHandler::class)]
#[CoversClass(RestoreGameSystemReleaseHandler::class)]
#[CoversClass(GetGameSystemReleaseContentHandler::class)]
#[CoversClass(GetCardImageHandler::class)]
#[CoversClass(CardImages::class)]
#[CoversClass(InMemoryCardImages::class)]
final class ReleaseCardImagePinsHandlersTest extends TestCase
{
    private InMemoryGameSystemRepository $gameSystems;
    private InMemoryGameSystemReleaseRepository $releases;
    private InMemoryCardImages $store;
    private CardImages $images;
    private FixedClock $clock;
    private int $releaseIds = 0;

    protected function setUp(): void
    {
        $this->gameSystems = new InMemoryGameSystemRepository();
        $this->releases = new InMemoryGameSystemReleaseRepository();
        $this->store = new InMemoryCardImages($this->releases);
        $this->images = new CardImages($this->store, $this->store, $this->store);
        $this->clock = new FixedClock('2026-11-27T10:00:00+00:00');
        new CreateGameSystemHandler($this->gameSystems, $this->releases, $this->clock)(new CreateGameSystem(new SequentialGameSystemIdGenerator()->generate()->toString(), 'ann', 'mine', 'Mine', null, null, null));
    }

    #[Test]
    public function publishingPinsTheDraftsImagesToTheNewReleaseOnly(): void
    {
        $this->upload(CardImageSlot::CardBack, 'back');
        $this->upload(CardImageSlot::CardFront, 'front');

        $this->publish();
        $this->upload(CardImageSlot::CardBack, 'new back');

        self::assertSame(['cardBack' => hash('sha256', 'back'), 'cardFront' => hash('sha256', 'front')], $this->images->releaseHashes($this->release(1)));
    }

    #[Test]
    public function aDraftWithoutImagesPublishesWithNoPins(): void
    {
        $this->publish();

        self::assertSame([], $this->images->releaseHashes($this->release(1)));
    }

    #[Test]
    public function aDraftWithTheContentAndTheImagesOfTheLatestReleaseIsUnchanged(): void
    {
        $this->upload(CardImageSlot::CardBack, 'back');
        $this->publish();

        $this->expectException(GameSystemDraftUnchanged::class);

        $this->publish();
    }

    #[Test]
    public function aNewImageAloneIsAChange(): void
    {
        $this->publish();
        $this->upload(CardImageSlot::CardBack, 'back');

        $this->publish();

        self::assertSame(['cardBack' => hash('sha256', 'back')], $this->images->releaseHashes($this->release(2)));
        self::assertSame([], $this->images->releaseHashes($this->release(1)), 'The first release never changes.');
    }

    #[Test]
    public function aReplacedImageAloneIsAChange(): void
    {
        $this->upload(CardImageSlot::CardBack, 'back');
        $this->publish();
        $this->upload(CardImageSlot::CardBack, 'other back');

        $this->publish();

        self::assertSame(['cardBack' => hash('sha256', 'other back')], $this->images->releaseHashes($this->release(2)));
    }

    #[Test]
    public function aRemovedImageAloneIsAChange(): void
    {
        $this->upload(CardImageSlot::CardBack, 'back');
        $this->publish();
        $this->images->remove($this->draftId(), CardImageSlot::CardBack);

        $this->publish();

        self::assertSame([], $this->images->releaseHashes($this->release(2)));
    }

    #[Test]
    public function aRefusedPublishPinsNothing(): void
    {
        $this->upload(CardImageSlot::CardBack, 'back');
        $this->publish();

        try {
            $this->publish();
            self::fail('The draft is unchanged.');
        } catch (GameSystemDraftUnchanged) {
            self::assertNull($this->releases->get('mine', 2));
            self::assertSame([], $this->images->releaseHashes(ReleaseId::fromString('release-2')));
        }
    }

    #[Test]
    public function aReplacedImageThatAReleasePinsIsKeptAndStillReadableByItsOwner(): void
    {
        $this->upload(CardImageSlot::CardBack, 'back');
        $this->publish();

        $this->upload(CardImageSlot::CardBack, 'other back');
        $this->images->remove($this->draftId(), CardImageSlot::CardBack);

        self::assertSame('back', $this->read('ann', hash('sha256', 'back'))->bytes);
        self::assertNull($this->store->get(hash('sha256', 'other back')), 'An image nobody pins is deleted.');
        self::assertSame([hash('sha256', 'back')], $this->store->hashes());
    }

    #[Test]
    public function aReleasePinnedImageIsNotReadableByAnotherManagerOrAnotherGameSystem(): void
    {
        $this->upload(CardImageSlot::CardBack, 'back');
        $this->publish();
        $this->upload(CardImageSlot::CardBack, 'other back');
        new CreateGameSystemHandler($this->gameSystems, $this->releases, $this->clock)(new CreateGameSystem('00000000-0000-7000-8000-000000000099', 'bob', 'theirs', 'Theirs', null, null, null));
        new CreateGameSystemHandler($this->gameSystems, $this->releases, $this->clock)(new CreateGameSystem('00000000-0000-7000-8000-000000000098', 'ann', 'second', 'Second', null, null, null));

        foreach ([['bob', 'mine'], ['bob', 'theirs'], ['ann', 'second']] as [$owner, $key]) {
            try {
                $this->read($owner, hash('sha256', 'back'), $key);
                self::fail($owner.' '.$key);
            } catch (GameSystemNotFound|CardImageNotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function restoringPinsTheReleasesImagesInTheDraftAndDeletesWhatNobodyPinsAnyMore(): void
    {
        $this->upload(CardImageSlot::CardBack, 'back');
        $this->publish();
        $this->upload(CardImageSlot::CardBack, 'other back');
        $this->upload(CardImageSlot::CardFront, 'front');

        $this->restore(1);

        self::assertSame(['cardBack' => hash('sha256', 'back')], $this->images->draftHashes($this->draftId()), 'The slot the release left empty is emptied.');
        self::assertSame([hash('sha256', 'back')], $this->store->hashes(), 'The images only the draft used are deleted.');
    }

    #[Test]
    public function restoringAReleaseWithoutImagesEmptiesBothSlots(): void
    {
        $this->publish();
        $this->upload(CardImageSlot::CardBack, 'back');

        $this->restore(1);

        self::assertSame([], $this->images->draftHashes($this->draftId()));
        self::assertSame([], $this->store->hashes());
    }

    #[Test]
    public function aPublishRightAfterARestoreIsUnchanged(): void
    {
        $this->upload(CardImageSlot::CardBack, 'back');
        $this->publish();
        $this->upload(CardImageSlot::CardBack, 'other back');
        $this->upload(CardImageSlot::CardFront, 'front');
        $this->restore(1);

        $this->expectException(GameSystemDraftUnchanged::class);

        $this->publish();
    }

    #[Test]
    public function aFailedRestoreLeavesTheDraftImagesAlone(): void
    {
        $this->upload(CardImageSlot::CardBack, 'back');

        try {
            $this->restore(4);
            self::fail('There is no release 4.');
        } catch (GameSystemReleaseNotFound) {
            self::assertSame(['cardBack' => hash('sha256', 'back')], $this->images->draftHashes($this->draftId()));
        }
    }

    #[Test]
    public function theContentViewHoldsTheContentAsPublishedAndThePinnedImagesInSlotOrder(): void
    {
        $this->upload(CardImageSlot::CardFront, 'front');
        $this->upload(CardImageSlot::CardBack, 'back');
        $this->publish();
        $this->upload(CardImageSlot::CardBack, 'other back');

        $view = $this->content('ann', 1);

        self::assertSame(['mine', 1], [$view->gameSystemKey, $view->version]);
        self::assertEquals($this->releases->get('mine', 1)?->content()->toArray(), $view->content);
        self::assertSame([[CardImageSlot::CardBack, hash('sha256', 'back')], [CardImageSlot::CardFront, hash('sha256', 'front')]], array_map(static fn (CardImagePin $pin): array => [$pin->slot, $pin->image->sha256], $view->cardImages));
    }

    #[Test]
    public function aReleasePublishedWithoutImagesHasNoCardImagesInItsContent(): void
    {
        $this->publish();
        $this->upload(CardImageSlot::CardBack, 'back');

        self::assertSame([], $this->content('ann', 1)->cardImages);
    }

    #[Test]
    public function theContentIsOwnerOnlyAndNeedsAnExistingRelease(): void
    {
        $this->publish();

        foreach ([['bob', 'mine', 1, GameSystemNotFound::class], ['ann', 'unknown', 1, GameSystemNotFound::class], ['ann', 'mine', 2, GameSystemReleaseNotFound::class]] as [$owner, $key, $version, $failure]) {
            try {
                $this->content($owner, $version, $key);
                self::fail($owner.' '.$key.' '.$version);
            } catch (\Throwable $thrown) {
                self::assertInstanceOf($failure, $thrown);
            }
        }
    }

    private function upload(CardImageSlot $slot, string $bytes): void
    {
        $this->images->replace($this->draftId(), $slot, new StoredImage(CardImage::of(hash('sha256', $bytes), CardImage::PNG, 815, 1110, \strlen($bytes)), $bytes));
    }

    private function draftId(): \App\Studio\Domain\GameSystem\GameSystemId
    {
        return $this->owned()->get('ann', 'mine')->id();
    }

    private function publish(): void
    {
        (new PublishGameSystemDraftHandler($this->owned(), new ReleasePublisher($this->releases, $this->clock), $this->releases, $this->images))(new PublishGameSystemDraft('ann', 'mine', 'release-'.++$this->releaseIds, null));
    }

    private function restore(int $version): void
    {
        (new RestoreGameSystemReleaseHandler($this->owned(), $this->gameSystems, $this->releases, $this->clock, $this->images))(new RestoreGameSystemRelease('ann', 'mine', $version));
    }

    private function release(int $version): ReleaseId
    {
        return $this->releases->get('mine', $version)?->id() ?? throw new \LogicException('No such release.');
    }

    private function read(string $owner, string $sha256, string $key = 'mine'): StoredImage
    {
        return (new GetCardImageHandler($this->owned(), $this->images, $this->store))(new GetCardImage($owner, $key, $sha256));
    }

    private function content(string $owner, int $version, string $key = 'mine'): GameSystemReleaseContentView
    {
        return (new GetGameSystemReleaseContentHandler($this->owned(), $this->releases, $this->store))(new GetGameSystemReleaseContent($owner, $key, $version));
    }

    private function owned(): OwnedGameSystems
    {
        return new OwnedGameSystems($this->gameSystems);
    }
}
