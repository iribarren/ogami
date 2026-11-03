<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\FlowSummaryView;
use App\Play\Application\GameSystemSummary;
use App\Play\Application\ListGameSystems;
use App\Play\Application\ListGameSystemsHandler;
use App\Play\Domain\GameSystem\Credits;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\Snapshots;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListGameSystems::class)]
#[CoversClass(ListGameSystemsHandler::class)]
#[CoversClass(GameSystemSummary::class)]
#[CoversClass(FlowSummaryView::class)]
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
    public function eachLatestReleaseListsItsFlows(): void
    {
        $releases = new InMemoryPublishedGameSystemReleases();
        $releases->add(Snapshots::bare('heist', 'Heist', 1));
        $releases->add(Snapshots::withFlows('heist', 'Heist', 2));

        $summaries = new ListGameSystemsHandler($releases)(new ListGameSystems());

        self::assertEquals([
            new GameSystemSummary('heist', 'Heist', null, 2, [
                new FlowSummaryView('the-heist', 'The heist', 'Plan it, pull it off, get away.', 'Every crew needs a score.', true, 'focus', [], []),
                new FlowSummaryView('one-shot', 'One shot', null, null, false, 'journal', [], []),
            ]),
        ], $summaries);
    }

    #[Test]
    public function eachLatestReleaseListsItsCredits(): void
    {
        $credits = new Credits('Unofficial fan content.', 'A policy', 'https://example.com/policy');
        $releases = new InMemoryPublishedGameSystemReleases();
        $releases->add(new GameSystemSnapshot('fan', 'Fan', 1, null, [], [], credits: $credits));
        $releases->add(Snapshots::bare('plain', 'Plain', 1));

        $summaries = new ListGameSystemsHandler($releases)(new ListGameSystems());

        self::assertEquals([
            new GameSystemSummary('fan', 'Fan', null, 1, [], $credits),
            new GameSystemSummary('plain', 'Plain', null, 1),
        ], $summaries);
    }

    #[Test]
    public function nothingIsListedWithoutReleases(): void
    {
        self::assertSame([], new ListGameSystemsHandler(new InMemoryPublishedGameSystemReleases())(new ListGameSystems()));
    }
}
