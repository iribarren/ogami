<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\CardImageNotFound;
use App\Studio\Application\CardImages;
use App\Studio\Application\DiscardGameSystem;
use App\Studio\Application\DiscardGameSystemHandler;
use App\Studio\Application\GetCardImage;
use App\Studio\Application\GetCardImageHandler;
use App\Studio\Application\ImageNormalizer;
use App\Studio\Application\ListCardImages;
use App\Studio\Application\ListCardImagesHandler;
use App\Studio\Application\OwnedGameSystems;
use App\Studio\Application\RemoveCardImage;
use App\Studio\Application\RemoveCardImageHandler;
use App\Studio\Application\StoredImage;
use App\Studio\Application\UploadCardImage;
use App\Studio\Application\UploadCardImageHandler;
use App\Studio\Application\UploadedCardImage;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Image\CardImage;
use App\Studio\Domain\Image\CardImageSlot;
use App\Studio\Domain\Image\ImageRefusalReason;
use App\Studio\Domain\Image\ImageRefused;
use App\Tests\Support\Studio\InMemoryCardImages;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use App\Tests\Support\Studio\InMemoryGameSystemRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UploadCardImageHandler::class)]
#[CoversClass(RemoveCardImageHandler::class)]
#[CoversClass(ListCardImagesHandler::class)]
#[CoversClass(GetCardImageHandler::class)]
#[CoversClass(DiscardGameSystemHandler::class)]
#[CoversClass(CardImages::class)]
final class CardImageHandlersTest extends TestCase
{
    private InMemoryGameSystemRepository $gameSystems;
    private InMemoryCardImages $store;
    private CardImages $images;
    private OwnedGameSystems $owned;

    protected function setUp(): void
    {
        $this->gameSystems = new InMemoryGameSystemRepository();
        $this->store = new InMemoryCardImages();
        $this->images = new CardImages($this->store, $this->store);
        $this->owned = new OwnedGameSystems($this->gameSystems);
        $this->gameSystems->add(GameSystem::createBlank(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a61'), 'mine', 'Mine', null, 'ann', new \DateTimeImmutable('2026-11-27T10:00:00')));
        $this->gameSystems->add(GameSystem::createBlank(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a62'), 'other', 'Other', null, 'bob', new \DateTimeImmutable('2026-11-27T10:00:00')));
    }

    #[Test]
    public function anUploadPinsTheNormalizedImageInTheSlot(): void
    {
        $this->upload('ann', 'mine', CardImageSlot::CardBack, 'one');

        $pins = $this->list('ann', 'mine');
        self::assertCount(1, $pins);
        self::assertSame(CardImageSlot::CardBack, $pins[0]->slot);
        self::assertSame(hash('sha256', 'one'), $pins[0]->image->sha256);
        self::assertSame('one', $this->read('ann', 'mine', hash('sha256', 'one'))->bytes);
    }

    #[Test]
    public function anUploadLeavesTheStoredImageInItsReceiptWithoutReadingThePinsAgain(): void
    {
        $receipt = new UploadedCardImage();

        $this->upload('ann', 'mine', CardImageSlot::CardBack, 'one', $receipt);
        $this->remove('ann', 'mine', CardImageSlot::CardBack);

        self::assertSame(hash('sha256', 'one'), $receipt->image()->sha256);
        self::assertSame([], $this->list('ann', 'mine'), 'The receipt does not depend on the pins.');
    }

    #[Test]
    public function aReceiptOfAnUnhandledUploadThrows(): void
    {
        $this->expectException(\LogicException::class);

        new UploadedCardImage()->image();
    }

    #[Test]
    public function aRefusedUploadLeavesNothingInTheReceipt(): void
    {
        $receipt = new UploadedCardImage();

        try {
            $this->upload('ann', 'mine', CardImageSlot::CardBack, 'refuse', $receipt);
            self::fail('The upload should be refused.');
        } catch (ImageRefused) {
            $this->expectException(\LogicException::class);
            $receipt->image();
        }
    }

    #[Test]
    public function aGameSystemWithNoImagesListsNone(): void
    {
        self::assertSame([], $this->list('ann', 'mine'));
    }

    #[Test]
    public function replacingASlotKeepsTheLastAndDeletesTheOldImage(): void
    {
        $this->upload('ann', 'mine', CardImageSlot::CardBack, 'one');
        $this->upload('ann', 'mine', CardImageSlot::CardBack, 'two');

        self::assertSame([hash('sha256', 'two')], array_map(static fn (\App\Studio\Domain\Image\CardImagePin $pin): string => $pin->image->sha256, $this->list('ann', 'mine')));
        self::assertSame([hash('sha256', 'two')], $this->store->hashes());
    }

    #[Test]
    public function anImageAnotherSlotStillUsesIsKept(): void
    {
        $this->upload('ann', 'mine', CardImageSlot::CardBack, 'same');
        $this->upload('ann', 'mine', CardImageSlot::CardFront, 'same');
        $this->upload('ann', 'mine', CardImageSlot::CardBack, 'other');

        self::assertEqualsCanonicalizing([hash('sha256', 'same'), hash('sha256', 'other')], $this->store->hashes());
    }

    #[Test]
    public function removingASlotDeletesItsImageAndAnEmptySlotStaysEmpty(): void
    {
        $this->upload('ann', 'mine', CardImageSlot::CardFront, 'one');

        $this->remove('ann', 'mine', CardImageSlot::CardFront);
        $this->remove('ann', 'mine', CardImageSlot::CardFront);

        self::assertSame([], $this->list('ann', 'mine'));
        self::assertSame([], $this->store->hashes());
    }

    #[Test]
    public function aRefusedUploadStoresNothingAndKeepsTheOldPin(): void
    {
        $this->upload('ann', 'mine', CardImageSlot::CardBack, 'one');

        try {
            $this->upload('ann', 'mine', CardImageSlot::CardBack, 'refuse');
            self::fail('The upload was accepted.');
        } catch (ImageRefused $refusal) {
            self::assertSame(ImageRefusalReason::Unreadable, $refusal->reason);
        }

        self::assertSame([hash('sha256', 'one')], $this->store->hashes());
        self::assertSame(hash('sha256', 'one'), $this->list('ann', 'mine')[0]->image->sha256);
    }

    #[Test]
    public function anUploadOverFiveMegabytesIsRefusedBeforeItIsDecoded(): void
    {
        try {
            $this->upload('ann', 'mine', CardImageSlot::CardBack, str_repeat('x', CardImage::MAX_UPLOAD_BYTES + 1));
            self::fail('The upload was accepted.');
        } catch (ImageRefused $refusal) {
            self::assertSame(ImageRefusalReason::TooLarge, $refusal->reason);
        }

        self::assertSame([], $this->store->hashes());
    }

    #[Test]
    public function anImageIsReadableOnlyWhenTheGameSystemUsesIt(): void
    {
        $this->upload('ann', 'mine', CardImageSlot::CardBack, 'one');
        $this->upload('bob', 'other', CardImageSlot::CardBack, 'theirs');

        $this->expectException(CardImageNotFound::class);

        $this->read('ann', 'mine', hash('sha256', 'theirs'));
    }

    #[Test]
    public function anUnknownHashIsNotFound(): void
    {
        $this->upload('ann', 'mine', CardImageSlot::CardBack, 'one');

        $this->expectException(CardImageNotFound::class);

        $this->read('ann', 'mine', str_repeat('0', 64));
    }

    #[Test]
    public function anotherOwnersGameSystemIsNotFoundOnEveryOperation(): void
    {
        $attempts = [
            fn () => $this->upload('bob', 'mine', CardImageSlot::CardBack, 'one'),
            fn () => $this->remove('bob', 'mine', CardImageSlot::CardBack),
            fn (): array => $this->list('bob', 'mine'),
            fn (): StoredImage => $this->read('bob', 'mine', str_repeat('0', 64)),
            fn (): array => $this->list('ann', 'unknown'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail('Another owner reached the images.');
            } catch (GameSystemNotFound) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->store->hashes());
    }

    #[Test]
    public function discardingAGameSystemDeletesImagesNobodyElseUses(): void
    {
        $this->upload('ann', 'mine', CardImageSlot::CardBack, 'mine-only');
        $this->upload('ann', 'mine', CardImageSlot::CardFront, 'shared');
        $this->upload('bob', 'other', CardImageSlot::CardFront, 'shared');

        new DiscardGameSystemHandler($this->owned, $this->gameSystems, new InMemoryGameSystemReleaseRepository(), $this->images)(new DiscardGameSystem('ann', 'mine'));

        self::assertSame([hash('sha256', 'shared')], $this->store->hashes());
    }

    private function upload(string $owner, string $key, CardImageSlot $slot, string $upload, ?UploadedCardImage $receipt = null): void
    {
        $normalizer = new class implements ImageNormalizer {
            public function normalize(string $upload): StoredImage
            {
                if ('refuse' === $upload) {
                    throw ImageRefused::because(ImageRefusalReason::Unreadable);
                }

                return new StoredImage(CardImage::of(hash('sha256', $upload), CardImage::PNG, 815, 1110, \strlen($upload)), $upload);
            }
        };

        new UploadCardImageHandler($this->owned, $normalizer, $this->images)(new UploadCardImage($owner, $key, $slot, $upload, $receipt ?? new UploadedCardImage()));
    }

    private function remove(string $owner, string $key, CardImageSlot $slot): void
    {
        new RemoveCardImageHandler($this->owned, $this->images)(new RemoveCardImage($owner, $key, $slot));
    }

    /**
     * @return list<\App\Studio\Domain\Image\CardImagePin>
     */
    private function list(string $owner, string $key): array
    {
        return new ListCardImagesHandler($this->owned, $this->store)(new ListCardImages($owner, $key));
    }

    private function read(string $owner, string $key, string $sha256): StoredImage
    {
        return new GetCardImageHandler($this->owned, $this->store, $this->store)(new GetCardImage($owner, $key, $sha256));
    }
}
