<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\CardImageNotFound;
use App\Studio\Application\CardImages;
use App\Studio\Application\CreateGameSystem;
use App\Studio\Application\CreateGameSystemHandler;
use App\Studio\Application\GetPublishedCardImage;
use App\Studio\Application\GetPublishedCardImageHandler;
use App\Studio\Application\GetPublishedReleaseContent;
use App\Studio\Application\GetPublishedReleaseContentHandler;
use App\Studio\Application\OwnedGameSystems;
use App\Studio\Application\PublishedCardImage;
use App\Studio\Application\PublishedCardImageView;
use App\Studio\Application\PublishedReleaseContentView;
use App\Studio\Application\PublishedReleaseNotFound;
use App\Studio\Application\PublishGameSystemDraft;
use App\Studio\Application\PublishGameSystemDraftHandler;
use App\Studio\Application\ReleasePublisher;
use App\Studio\Application\StoredImage;
use App\Studio\Domain\Image\CardImage;
use App\Studio\Domain\Image\CardImageSlot;
use App\Tests\Support\Studio\FixedClock;
use App\Tests\Support\Studio\InMemoryCardImages;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use App\Tests\Support\Studio\InMemoryGameSystemRepository;
use App\Tests\Support\Studio\SequentialGameSystemIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What Play may read of a published release through the Published Language: its content with the
 * images it pinned, and the bytes of exactly those images. Never a draft, never another release (ADR 0010, 0053).
 */
#[CoversClass(GetPublishedReleaseContentHandler::class)]
#[CoversClass(GetPublishedCardImageHandler::class)]
#[CoversClass(PublishedReleaseContentView::class)]
#[CoversClass(PublishedCardImageView::class)]
#[CoversClass(PublishedCardImage::class)]
final class GetPublishedReleaseContentHandlerTest extends TestCase
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
        $this->clock = new FixedClock('2026-11-28T10:00:00+00:00');
        new CreateGameSystemHandler($this->gameSystems, $this->releases, $this->clock)(new CreateGameSystem(new SequentialGameSystemIdGenerator()->generate()->toString(), 'ann', 'mine', 'Mine', null, null, null));
    }

    #[Test]
    public function theContentIsTheReleaseAsPublishedWithItsPinnedImagesInSlotOrder(): void
    {
        $this->upload(CardImageSlot::CardFront, 'front');
        $this->upload(CardImageSlot::CardBack, 'back');
        $this->publish();
        $this->upload(CardImageSlot::CardBack, 'other back');

        $view = $this->content(1);

        self::assertSame(['mine', 1], [$view->gameSystemKey, $view->version]);
        self::assertEquals(new \DateTimeImmutable('2026-11-28T10:00:00+00:00'), $view->publishedAt);
        self::assertEquals($this->releases->get('mine', 1)?->content()->toArray(), $view->content);
        self::assertEquals(
            [new PublishedCardImageView('cardBack', hash('sha256', 'back'), 'image/png', 815, 1110), new PublishedCardImageView('cardFront', hash('sha256', 'front'), 'image/png', 815, 1110)],
            $view->cardImages,
        );
    }

    #[Test]
    public function aReleasePublishedWithoutImagesHasNoCardImagesWhateverTheDraftHoldsNow(): void
    {
        $this->publish();
        $this->upload(CardImageSlot::CardBack, 'back');

        self::assertSame([], $this->content(1)->cardImages);
    }

    #[Test]
    public function aReleaseThatDoesNotExistHasNoContent(): void
    {
        $this->publish();

        $this->expectException(PublishedReleaseNotFound::class);

        $this->content(2);
    }

    #[Test]
    public function theImageOfThePinnedReleaseIsReadWithItsMediaType(): void
    {
        $this->upload(CardImageSlot::CardBack, 'back');
        $this->publish();

        $image = $this->image(1, hash('sha256', 'back'));

        self::assertSame(['image/png', 'back'], [$image->mediaType, $image->bytes]);
    }

    #[Test]
    public function anImageAnotherReleaseOfTheSameGameSystemPinsIsNotFoundInThisOne(): void
    {
        $this->upload(CardImageSlot::CardBack, 'back');
        $this->publish();
        $this->upload(CardImageSlot::CardBack, 'newer back');
        $this->publish();

        $this->expectException(CardImageNotFound::class);

        $this->image(1, hash('sha256', 'newer back'));
    }

    #[Test]
    public function anImageOnlyTheDraftUsesIsNotFound(): void
    {
        $this->publish();
        $this->upload(CardImageSlot::CardBack, 'draft only');

        $this->expectException(CardImageNotFound::class);

        $this->image(1, hash('sha256', 'draft only'));
    }

    #[Test]
    public function anUnknownHashIsNotFoundAndAReleaseThatDoesNotExistHasNoImages(): void
    {
        $this->publish();

        try {
            $this->image(1, str_repeat('a', 64));
            self::fail('No such image.');
        } catch (CardImageNotFound) {
            $this->expectException(PublishedReleaseNotFound::class);
            $this->image(2, str_repeat('a', 64));
        }
    }

    private function upload(CardImageSlot $slot, string $bytes): void
    {
        $id = new OwnedGameSystems($this->gameSystems)->get('ann', 'mine')->id();
        $this->images->replace($id, $slot, new StoredImage(CardImage::of(hash('sha256', $bytes), CardImage::PNG, 815, 1110, \strlen($bytes)), $bytes));
    }

    private function publish(): void
    {
        (new PublishGameSystemDraftHandler(new OwnedGameSystems($this->gameSystems), new ReleasePublisher($this->releases, $this->clock), $this->releases, $this->images))(new PublishGameSystemDraft('ann', 'mine', 'release-'.++$this->releaseIds, null));
    }

    private function content(int $version): PublishedReleaseContentView
    {
        return (new GetPublishedReleaseContentHandler($this->releases, $this->store))(new GetPublishedReleaseContent('mine', $version));
    }

    private function image(int $version, string $sha256): PublishedCardImage
    {
        return (new GetPublishedCardImageHandler($this->releases, $this->store, $this->store))(new GetPublishedCardImage('mine', $version, $sha256));
    }
}
