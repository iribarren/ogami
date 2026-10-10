<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\FlowSummaryView;
use App\Play\Application\GameSystemSummary;
use App\Play\Application\ListGameSystems;
use App\Play\Application\ListGameSystemsHandler;
use App\Play\Application\PublishedGameSystemReleases;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\Snapshots;
use App\Tests\Support\Play\UnreadablePublishedGameSystemReleases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
                new FlowSummaryView('the-heist', 'The heist', 'Plan it, pull it off, get away.', 'Every crew needs a score.', true, 'focus'),
                new FlowSummaryView('one-shot', 'One shot', null, null, false, 'journal'),
            ]),
        ], $summaries);
    }

    /**
     * @return iterable<string, array{GameSystemReleaseNotFound|InvalidGameSystemRelease|UnsupportedReleaseSchemaVersion}>
     */
    public static function errors(): iterable
    {
        return UnreadablePublishedGameSystemReleases::errors();
    }

    /**
     * The catalog still lists a release Play cannot read, as before releases had Flows; creating a
     * campaign with it fails as it always did.
     */
    #[Test]
    #[DataProvider('errors')]
    public function aLatestReleaseThatCannotBeReadIsListedWithoutFlows(GameSystemReleaseNotFound|InvalidGameSystemRelease|UnsupportedReleaseSchemaVersion $error): void
    {
        $releases = new readonly class($error) implements PublishedGameSystemReleases {
            public function __construct(private GameSystemReleaseNotFound|InvalidGameSystemRelease|UnsupportedReleaseSchemaVersion $error)
            {
            }

            public function get(string $gameSystemKey, ?int $version = null): GameSystemSnapshot
            {
                throw $this->error;
            }

            public function latest(): array
            {
                return [new GameSystemSummary('free-journal', 'Free journal', null, 1)];
            }
        };

        self::assertEquals([new GameSystemSummary('free-journal', 'Free journal', null, 1)], new ListGameSystemsHandler($releases)(new ListGameSystems()));
    }

    #[Test]
    public function nothingIsListedWithoutReleases(): void
    {
        self::assertSame([], new ListGameSystemsHandler(new InMemoryPublishedGameSystemReleases())(new ListGameSystems()));
    }
}
