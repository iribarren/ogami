<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio;

use App\Studio\Application\CardImages;
use App\Studio\Application\StoredImage;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\Image\CardImage;
use App\Studio\Domain\Image\CardImagePin;
use App\Studio\Domain\Image\CardImageSlot;
use App\Studio\Infrastructure\Image\GdImageNormalizer;
use App\Studio\Infrastructure\Persistence\Doctrine\DbalImageStore;
use App\Studio\Infrastructure\Persistence\Doctrine\DoctrineGameSystemRepository;
use App\Tests\Support\Studio\TestImages;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(DbalImageStore::class)]
final class DbalImageStoreTest extends KernelTestCase
{
    private const string FIRST_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a71';
    private const string SECOND_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a72';

    private Connection $connection;
    private DbalImageStore $store;
    private DoctrineGameSystemRepository $gameSystems;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->store = new DbalImageStore($this->connection);
        $this->gameSystems = new DoctrineGameSystemRepository($this->entityManager);
        $this->gameSystems->add(GameSystem::createBlank(GameSystemId::fromString(self::FIRST_ID), 'first', 'First', null, 'ann', new \DateTimeImmutable('2026-11-27T10:00:00')));
        $this->gameSystems->add(GameSystem::createBlank(GameSystemId::fromString(self::SECOND_ID), 'second', 'Second', null, 'ann', new \DateTimeImmutable('2026-11-27T10:00:00')));
    }

    #[Test]
    public function itRoundTripsTheWholeImageIncludingItsBytes(): void
    {
        $image = $this->image(TestImages::png());

        $this->store->put($image);

        self::assertEquals($image, $this->store->get($image->image->sha256));
        self::assertNull($this->store->get(str_repeat('0', 64)));
    }

    #[Test]
    public function savingTheSameImageTwiceKeepsOneRow(): void
    {
        $image = $this->image(TestImages::jpeg());

        $this->store->put($image);
        $this->store->put($image);

        self::assertSame(1, $this->rows('studio_image'));
    }

    #[Test]
    public function aGameSystemThatNeverHadImagesHasNoPins(): void
    {
        self::assertSame([], $this->store->of(GameSystemId::fromString(self::FIRST_ID)));
    }

    #[Test]
    public function pinsRoundTripPerSlotAndReportThePreviousHash(): void
    {
        $back = $this->image(TestImages::png());
        $front = $this->image(TestImages::jpeg());
        $first = GameSystemId::fromString(self::FIRST_ID);
        $this->store->put($back);
        $this->store->put($front);

        self::assertNull($this->store->pin($first, CardImageSlot::CardBack, $back->image->sha256));
        self::assertNull($this->store->pin($first, CardImageSlot::CardFront, $back->image->sha256));
        self::assertSame($back->image->sha256, $this->store->pin($first, CardImageSlot::CardFront, $front->image->sha256));

        $pins = $this->store->of($first);
        self::assertSame([CardImageSlot::CardBack, CardImageSlot::CardFront], array_map(static fn (CardImagePin $pin): CardImageSlot => $pin->slot, $pins));
        self::assertEquals([$back->image, $front->image], array_map(static fn (CardImagePin $pin): CardImage => $pin->image, $pins));
        self::assertSame([], $this->store->of(GameSystemId::fromString(self::SECOND_ID)));
        self::assertTrue($this->store->isPinned($first, $front->image->sha256));
        self::assertFalse($this->store->isPinned(GameSystemId::fromString(self::SECOND_ID), $front->image->sha256));
    }

    #[Test]
    public function unpinningReportsThePreviousHashAndAnEmptySlotReportsNone(): void
    {
        $image = $this->image(TestImages::png());
        $first = GameSystemId::fromString(self::FIRST_ID);
        $this->store->put($image);
        $this->store->pin($first, CardImageSlot::CardBack, $image->image->sha256);

        self::assertSame($image->image->sha256, $this->store->unpin($first, CardImageSlot::CardBack));
        self::assertNull($this->store->unpin($first, CardImageSlot::CardBack));
        self::assertSame([], $this->store->of($first));
    }

    #[Test]
    public function anImageAPinUsesIsNotRemoved(): void
    {
        $image = $this->image(TestImages::png());
        $this->store->put($image);
        $this->store->pin(GameSystemId::fromString(self::FIRST_ID), CardImageSlot::CardBack, $image->image->sha256);

        $this->store->removeIfUnreferenced($image->image->sha256);
        self::assertNotNull($this->store->get($image->image->sha256));

        $this->store->unpin(GameSystemId::fromString(self::FIRST_ID), CardImageSlot::CardBack);
        $this->store->removeIfUnreferenced($image->image->sha256);
        self::assertNull($this->store->get($image->image->sha256));
    }

    #[Test]
    public function deletingTheGameSystemDeletesItsPins(): void
    {
        $image = $this->image(TestImages::png());
        $this->store->put($image);
        $this->store->pin(GameSystemId::fromString(self::FIRST_ID), CardImageSlot::CardBack, $image->image->sha256);

        $this->gameSystems->remove($this->gameSystems->ofKey('first') ?? throw new \LogicException('Missing.'));

        self::assertSame(0, $this->rows('studio_card_image_pin'));
        self::assertSame(1, $this->rows('studio_image'), 'The image stays until it is released.');
    }

    #[Test]
    public function aStoredRowThatBreaksTheRulesIsRefusedWhenReadBack(): void
    {
        $image = $this->image(TestImages::png());
        $this->store->put($image);
        $first = GameSystemId::fromString(self::FIRST_ID);
        $this->store->pin($first, CardImageSlot::CardBack, $image->image->sha256);
        $this->connection->executeStatement('UPDATE studio_image SET width = 99999');

        $this->expectException(\UnexpectedValueException::class);

        $this->store->of($first);
    }

    #[Test]
    public function aStoredRowWhoseBytesChangedIsRefusedWhenReadBack(): void
    {
        $image = $this->image(TestImages::png());
        $this->store->put($image);
        $this->connection->executeStatement("UPDATE studio_image SET data = '\\x00'::bytea");

        $this->expectException(\UnexpectedValueException::class);

        $this->store->get($image->image->sha256);
    }

    #[Test]
    public function pinningAnEmptySlotLocksTheGameSystemAgainstOtherRequests(): void
    {
        $image = $this->image(TestImages::png());
        $first = GameSystemId::fromString(self::FIRST_ID);
        $this->store->put($image);

        // No pin row exists yet, so a row lock would have locked nothing.
        $this->store->pin($first, CardImageSlot::CardBack, $image->image->sha256);

        self::assertFalse($this->otherRequestGets(DbalImageStore::pinsLockKey($first)), 'A second first upload must wait.');
        self::assertTrue($this->otherRequestGets(DbalImageStore::pinsLockKey(GameSystemId::fromString(self::SECOND_ID))), 'Another GameSystem is not held up.');
    }

    #[Test]
    public function readingAnEmptySlotToUnpinItLocksTheGameSystemToo(): void
    {
        $first = GameSystemId::fromString(self::FIRST_ID);

        self::assertNull($this->store->unpin($first, CardImageSlot::CardFront));

        self::assertFalse($this->otherRequestGets(DbalImageStore::pinsLockKey($first)));
    }

    #[Test]
    public function aSecondFirstUploadSeesTheFirstOnesImageAndReleasesIt(): void
    {
        $back = $this->image(TestImages::png());
        $other = $this->image(TestImages::jpeg());
        $first = GameSystemId::fromString(self::FIRST_ID);
        $images = new CardImages($this->store, $this->store);

        // The order the race would have produced: both uploads start, the later one pins last.
        $images->replace($first, CardImageSlot::CardBack, $back);
        $images->replace($first, CardImageSlot::CardBack, $other);

        self::assertSame(1, $this->rows('studio_image'), 'No blob is left orphaned.');
        self::assertNull($this->store->get($back->image->sha256));
    }

    #[Test]
    public function putHoldsTheImageLockSoARemovalOfTheSameBytesWaitsForThePin(): void
    {
        $image = $this->image(TestImages::png());

        $this->store->put($image);

        self::assertFalse($this->otherRequestGets(DbalImageStore::imageLockKey($image->image->sha256)), 'A removal of the same bytes must wait until the pin is written.');
        self::assertTrue($this->otherRequestGets(DbalImageStore::imageLockKey(str_repeat('a', 64))));
    }

    #[Test]
    public function removingAnImageTakesItsLockToo(): void
    {
        $image = $this->image(TestImages::png());

        $this->store->removeIfUnreferenced($image->image->sha256);

        self::assertFalse($this->otherRequestGets(DbalImageStore::imageLockKey($image->image->sha256)));
    }

    #[Test]
    public function aRemovalAfterTheSameBytesWerePutAndPinnedKeepsTheImage(): void
    {
        $image = $this->image(TestImages::png());
        $first = GameSystemId::fromString(self::FIRST_ID);
        $images = new CardImages($this->store, $this->store);
        $images->replace($first, CardImageSlot::CardBack, $image);

        // What the slower request of the race does last: release the blob it replaced, which is the same bytes.
        $this->store->removeIfUnreferenced($image->image->sha256);

        self::assertEquals([$image->image], array_map(static fn (CardImagePin $pin): CardImage => $pin->image, $this->store->of($first)));
        self::assertEquals($image, $this->store->get($image->image->sha256));
    }

    /**
     * Whether a request on its own connection, in its own transaction, can take the advisory lock now.
     */
    private function otherRequestGets(string $key): bool
    {
        // @phpstan-ignore argument.type (the parameters of the container's own connection: the same database, as another request has it)
        $other = DriverManager::getConnection($this->connection->getParams());
        try {
            $other->beginTransaction();

            return true === $other->fetchOne('SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
        } finally {
            $other->close();
        }
    }

    private function image(string $upload): StoredImage
    {
        return new GdImageNormalizer()->normalize($upload);
    }

    private function rows(string $table): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM '.$table);
        self::assertIsInt($count);

        return $count;
    }
}
