<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Play\Infrastructure\GameSystem\StudioPublishedGameSystemReleases;
use App\Shared\Application\Bus\Query;
use App\Shared\Application\Bus\QueryBus;
use App\Studio\Application\PublishedReleaseNotFound;
use App\Studio\Application\PublishedReleaseUnreadable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StudioPublishedGameSystemReleases::class)]
#[CoversClass(InvalidGameSystemRelease::class)]
#[CoversClass(GameSystemReleaseNotFound::class)]
final class StudioPublishedGameSystemReleasesTest extends TestCase
{
    #[Test]
    public function anUnreadableStudioReleaseBecomesAnInvalidGameSystemRelease(): void
    {
        $unreadable = new PublishedReleaseUnreadable('free-journal', 3, 'oracles.tables[0].key', 'must not be empty.');

        try {
            $this->releasesFailingWith($unreadable)->get('free-journal');
            self::fail('Expected InvalidGameSystemRelease.');
        } catch (InvalidGameSystemRelease $exception) {
            self::assertStringContainsString('GameSystem "free-journal" v3 cannot be read by Play: oracles.tables[0].key: must not be empty.', $exception->getMessage());
            self::assertSame($unreadable, $exception->getPrevious());
        }
    }

    #[Test]
    public function aMissingStudioReleaseBecomesGameSystemReleaseNotFound(): void
    {
        $this->expectException(GameSystemReleaseNotFound::class);

        $this->releasesFailingWith(PublishedReleaseNotFound::for('free-journal', 2))->get('free-journal', 2);
    }

    private function releasesFailingWith(\Throwable $failure): StudioPublishedGameSystemReleases
    {
        return new StudioPublishedGameSystemReleases(
            new readonly class($failure) implements QueryBus {
                public function __construct(private \Throwable $failure)
                {
                }

                public function ask(Query $query): mixed
                {
                    throw $this->failure;
                }
            },
            new GameSystemReleaseTranslator(),
        );
    }
}
