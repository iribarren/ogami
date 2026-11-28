<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Application\CardImageNotFound;
use App\Play\Application\CardImageUnavailable;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Infrastructure\GameSystem\StudioPublishedReleaseContents;
use App\Shared\Application\Bus\Query;
use App\Shared\Application\Bus\QueryBus;
use App\Studio\Application\CardImageNotFound as StudioCardImageNotFound;
use App\Studio\Application\PublishedCardImageMissing;
use App\Studio\Application\PublishedReleaseNotFound;
use App\Studio\Application\PublishedReleaseUnreadable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What Studio's Published Language fails with becomes a Play failure that is never swallowed: an
 * unreadable release or a pinned image with no bytes is a fault (500), not an absent resource (404).
 */
#[CoversClass(StudioPublishedReleaseContents::class)]
#[CoversClass(CardImageUnavailable::class)]
final class StudioPublishedReleaseContentsTest extends TestCase
{
    private const string HASH = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[Test]
    public function anUnreadableReleaseFailsLoudlyWithTheStudioFailureAsItsCause(): void
    {
        $unreadable = new PublishedReleaseUnreadable('mine', 3, 'oracles.tables[0].key', 'must not be empty.');

        try {
            $this->failingWith($unreadable)->content('mine', 3);
            self::fail('Expected InvalidGameSystemRelease.');
        } catch (InvalidGameSystemRelease $exception) {
            self::assertStringContainsString('oracles.tables[0].key: must not be empty.', $exception->getMessage());
            self::assertSame($unreadable, $exception->getPrevious());
        }
    }

    #[Test]
    public function aMissingReleaseIsNotFoundForTheContentAndForTheImage(): void
    {
        $missing = PublishedReleaseNotFound::for('mine', 2);
        $found = 0;

        foreach ([
            fn (): \App\Play\Application\ReleaseContentView => $this->failingWith($missing)->content('mine', 2),
            fn (): \App\Play\Application\CardImageBytes => $this->failingWith($missing)->cardImage('mine', 2, self::HASH),
        ] as $read) {
            try {
                $read();
            } catch (GameSystemReleaseNotFound) {
                ++$found;
            }
        }

        self::assertSame(2, $found);
    }

    #[Test]
    public function anUnpinnedHashStaysNotFound(): void
    {
        $this->expectException(CardImageNotFound::class);

        $this->failingWith(StudioCardImageNotFound::withHash(self::HASH))->cardImage('mine', 1, self::HASH);
    }

    #[Test]
    public function aPinnedImageWithoutBytesIsUnavailableNeverNotFound(): void
    {
        $missing = PublishedCardImageMissing::withHash(self::HASH);

        try {
            $this->failingWith($missing)->cardImage('mine', 1, self::HASH);
            self::fail('Expected CardImageUnavailable.');
        } catch (CardImageUnavailable $exception) {
            self::assertSame($missing, $exception->getPrevious());
        }
    }

    private function failingWith(\Throwable $failure): StudioPublishedReleaseContents
    {
        return new StudioPublishedReleaseContents(new readonly class($failure) implements QueryBus {
            public function __construct(private \Throwable $failure)
            {
            }

            public function ask(Query $query): mixed
            {
                throw $this->failure;
            }
        });
    }
}
