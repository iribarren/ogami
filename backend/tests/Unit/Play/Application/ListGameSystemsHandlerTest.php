<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\GameSystemSummary;
use App\Play\Application\ListGameSystems;
use App\Play\Application\ListGameSystemsHandler;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\Snapshots;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListGameSystems::class)]
#[CoversClass(ListGameSystemsHandler::class)]
#[CoversClass(GameSystemSummary::class)]
final class ListGameSystemsHandlerTest extends TestCase
{
    #[Test]
    public function itListsTheLatestReleaseOfEachGameSystem(): void
    {
        $releases = new InMemoryPublishedGameSystemReleases();
        $releases->add(Snapshots::bare('free-journal', 'Free journal', 1), 'A journal.');
        $releases->add(Snapshots::bare('free-journal', 'Free journal, revised', 2), 'A revised journal.');
        $releases->add(Snapshots::bare('ironsworn', 'Ironsworn', 3));

        $summaries = new ListGameSystemsHandler($releases)(new ListGameSystems());

        self::assertEquals([
            new GameSystemSummary('free-journal', 'Free journal, revised', 'A revised journal.', 2),
            new GameSystemSummary('ironsworn', 'Ironsworn', null, 3),
        ], $summaries);
    }

    #[Test]
    public function nothingIsListedWithoutReleases(): void
    {
        self::assertSame([], new ListGameSystemsHandler(new InMemoryPublishedGameSystemReleases())(new ListGameSystems()));
    }
}
