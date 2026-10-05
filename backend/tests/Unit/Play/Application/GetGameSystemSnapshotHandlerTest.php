<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\GetGameSystemSnapshot;
use App\Play\Application\GetGameSystemSnapshotHandler;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetGameSystemSnapshot::class)]
#[CoversClass(GetGameSystemSnapshotHandler::class)]
#[CoversClass(GameSystemReleaseNotFound::class)]
final class GetGameSystemSnapshotHandlerTest extends TestCase
{
    private GetGameSystemSnapshotHandler $handler;

    protected function setUp(): void
    {
        $releases = new InMemoryPublishedGameSystemReleases();
        $releases->add(new GameSystemSnapshot('free-journal', 'Free journal', 1, null, [], []));
        $releases->add(new GameSystemSnapshot('free-journal', 'Free journal, revised', 2, null, [], []));
        $this->handler = new GetGameSystemSnapshotHandler($releases);
    }

    #[Test]
    public function itGivesTheLatestSnapshotWhenNoVersionIsAsked(): void
    {
        $snapshot = ($this->handler)(new GetGameSystemSnapshot('free-journal'));

        self::assertSame(2, $snapshot->releaseVersion());
        self::assertSame('Free journal, revised', $snapshot->name());
    }

    #[Test]
    public function itGivesTheAskedVersion(): void
    {
        $snapshot = ($this->handler)(new GetGameSystemSnapshot('free-journal', 1));

        self::assertSame(1, $snapshot->releaseVersion());
        self::assertSame('Free journal', $snapshot->name());
    }

    #[Test]
    public function anUnknownGameSystemIsNotFound(): void
    {
        $this->expectException(GameSystemReleaseNotFound::class);
        $this->expectExceptionMessageIsOrContains('No published release of GameSystem "unknown" is available to Play.');

        ($this->handler)(new GetGameSystemSnapshot('unknown'));
    }

    #[Test]
    public function anUnknownVersionIsNotFound(): void
    {
        $this->expectException(GameSystemReleaseNotFound::class);
        $this->expectExceptionMessageIsOrContains('No published release v3 of GameSystem "free-journal" is available to Play.');

        ($this->handler)(new GetGameSystemSnapshot('free-journal', 3));
    }
}
