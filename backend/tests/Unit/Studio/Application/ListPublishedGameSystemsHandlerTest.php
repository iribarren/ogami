<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\ListPublishedGameSystems;
use App\Studio\Application\ListPublishedGameSystemsHandler;
use App\Studio\Application\PublishedGameSystemSummary;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListPublishedGameSystems::class)]
#[CoversClass(ListPublishedGameSystemsHandler::class)]
#[CoversClass(PublishedGameSystemSummary::class)]
final class ListPublishedGameSystemsHandlerTest extends TestCase
{
    private InMemoryGameSystemReleaseRepository $releases;
    private ListPublishedGameSystemsHandler $handler;

    protected function setUp(): void
    {
        $this->releases = new InMemoryGameSystemReleaseRepository();
        $this->handler = new ListPublishedGameSystemsHandler($this->releases);
    }

    #[Test]
    public function nothingIsListedBeforeAnyRelease(): void
    {
        self::assertSame([], ($this->handler)(new ListPublishedGameSystems()));
    }

    #[Test]
    public function itListsTheLatestReleaseOfEachGameSystem(): void
    {
        $this->publish('r-1', 'free-journal', 'Free journal', 1, '2026-10-05T10:00:00+00:00', 'A journal.');
        $this->publish('r-2', 'free-journal', 'Free journal, revised', 2, '2026-10-06T10:00:00+00:00', 'A revised journal.');
        $this->publish('r-3', 'ironsworn', 'Ironsworn', 1, '2026-10-04T10:00:00+00:00');

        $summaries = ($this->handler)(new ListPublishedGameSystems());

        self::assertCount(2, $summaries);
        self::assertSame('free-journal', $summaries[0]->gameSystemKey);
        self::assertSame('Free journal, revised', $summaries[0]->name);
        self::assertSame('A revised journal.', $summaries[0]->description);
        self::assertSame(2, $summaries[0]->version);
        self::assertEquals(new \DateTimeImmutable('2026-10-06T10:00:00+00:00'), $summaries[0]->publishedAt);
        self::assertSame('ironsworn', $summaries[1]->gameSystemKey);
        self::assertNull($summaries[1]->description);
        self::assertSame(1, $summaries[1]->version);
    }

    #[Test]
    public function theListIsOrderedByNameThenKey(): void
    {
        $this->publish('r-1', 'zeta', 'Same name', 1, '2026-10-05T10:00:00+00:00');
        $this->publish('r-2', 'alpha', 'Same name', 1, '2026-10-05T10:00:00+00:00');
        $this->publish('r-3', 'middle', 'Another name', 1, '2026-10-05T10:00:00+00:00');

        $keys = array_map(
            static fn (PublishedGameSystemSummary $summary): string => $summary->gameSystemKey,
            ($this->handler)(new ListPublishedGameSystems()),
        );

        self::assertSame(['middle', 'alpha', 'zeta'], $keys);
    }

    #[Test]
    public function namesAreOrderedIgnoringCase(): void
    {
        $this->publish('r-1', 'b-upper', 'Beta', 1, '2026-10-05T10:00:00+00:00');
        $this->publish('r-2', 'a-lower', 'alpha', 1, '2026-10-05T10:00:00+00:00');
        $this->publish('r-3', 'c-lower', 'charlie', 1, '2026-10-05T10:00:00+00:00');
        $this->publish('r-4', 'a-upper', 'Alpha', 1, '2026-10-05T10:00:00+00:00');

        $keys = array_map(
            static fn (PublishedGameSystemSummary $summary): string => $summary->gameSystemKey,
            ($this->handler)(new ListPublishedGameSystems()),
        );

        // "alpha" and "Alpha" tie on the name, so the key decides.
        self::assertSame(['a-lower', 'a-upper', 'b-upper', 'c-lower'], $keys);
    }

    private function publish(string $id, string $key, string $name, int $version, string $publishedAt, ?string $description = null): void
    {
        $gameSystem = ['key' => $key, 'name' => $name];
        if (null !== $description) {
            $gameSystem['description'] = $description;
        }

        $content = ReleaseContent::fromArray([
            'schemaVersion' => 1,
            'gameSystem' => $gameSystem,
            'oracles' => ['tables' => [], 'likelihood' => []],
            'flow' => ['steps' => []],
            'sheet' => [],
            'checks' => [],
        ]);

        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString($id), $content, $version, new \DateTimeImmutable($publishedAt)));
    }
}
