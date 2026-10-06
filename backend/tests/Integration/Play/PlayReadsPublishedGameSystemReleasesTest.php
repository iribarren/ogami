<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play;

use App\Play\Application\GameSystemSummary;
use App\Play\Application\GetGameSystemSnapshot;
use App\Play\Application\ListGameSystems;
use App\Play\Application\PublishedGameSystemReleases;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Infrastructure\GameSystem\StudioPublishedGameSystemReleases;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Play reads GameSystem releases Studio published, through its anti-corruption layer.
 */
#[CoversClass(StudioPublishedGameSystemReleases::class)]
final class PlayReadsPublishedGameSystemReleasesTest extends KernelTestCase
{
    private const string FIRST_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a71';
    private const string SECOND_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a72';

    private QueryBus $queries;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->queries = $container->get(QueryBus::class);

        $commands = $container->get(CommandBus::class);
        $content = ReleaseViews::contractDocExampleContent();
        $commands->dispatch(new PublishGameSystemRelease(self::FIRST_ID, $content, false));

        self::assertIsArray($content['gameSystem']);
        $content['gameSystem']['name'] = 'Example journal, revised';
        $commands->dispatch(new PublishGameSystemRelease(self::SECOND_ID, $content, false));
    }

    #[Test]
    public function playGetsTheLatestSnapshotThroughItsQuery(): void
    {
        $snapshot = $this->queries->ask(new GetGameSystemSnapshot('example-journal'));

        self::assertSame('example-journal', $snapshot->gameSystemKey());
        self::assertSame(2, $snapshot->releaseVersion());
        self::assertSame('Example journal, revised', $snapshot->name());
        self::assertSame(['weather', 'storm-kind'], $snapshot->oracleTableKeys());
        self::assertSame('Fate question', $snapshot->likelihoodOracle('fate')->name());
        self::assertSame('set-scene', $snapshot->flowSteps()[0]->key());
    }

    #[Test]
    public function playGetsAnEarlierVersionThroughItsPort(): void
    {
        $snapshot = self::getContainer()->get(PublishedGameSystemReleases::class)->get('example-journal', 1);

        self::assertSame(1, $snapshot->releaseVersion());
        self::assertSame('Example journal', $snapshot->name());
        $result = $snapshot->resolveOracleTable('weather', new ScriptedRandomNumberGenerator(2));
        self::assertSame('Clear', $result->steps()[0]->text());
    }

    #[Test]
    public function playListsTheLatestReleaseOfEachGameSystemThroughItsQuery(): void
    {
        $summaries = $this->queries->ask(new ListGameSystems());

        self::assertEquals([
            new GameSystemSummary('example-journal', 'Example journal, revised', 'A minimal game system that shows every part of the contract.', 2),
        ], $summaries);
    }

    #[Test]
    public function playListsGameSystemsByNameWhateverTheCase(): void
    {
        $commands = self::getContainer()->get(CommandBus::class);
        foreach (['0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a73' => 'beta', '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a74' => 'Alpha', '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a75' => 'gamma'] as $id => $name) {
            $content = ReleaseViews::contractDocExampleContent();
            self::assertIsArray($content['gameSystem']);
            $content['gameSystem']['key'] = strtolower($name).'-journal';
            $content['gameSystem']['name'] = $name;
            $commands->dispatch(new PublishGameSystemRelease($id, $content, false));
        }

        $summaries = $this->queries->ask(new ListGameSystems());

        // A byte-order sort would put "Example…" before "beta".
        self::assertSame(
            ['Alpha', 'beta', 'Example journal, revised', 'gamma'],
            array_map(static fn (GameSystemSummary $summary): string => $summary->name, $summaries),
        );
    }

    #[Test]
    public function anUnknownGameSystemIsPlaysOwnNotFoundError(): void
    {
        $this->expectException(GameSystemReleaseNotFound::class);
        $this->expectExceptionMessageIsOrContains('No published release of GameSystem "unknown" is available to Play.');

        $this->queries->ask(new GetGameSystemSnapshot('unknown'));
    }

    #[Test]
    public function anUnknownVersionIsPlaysOwnNotFoundError(): void
    {
        $this->expectException(GameSystemReleaseNotFound::class);
        $this->expectExceptionMessageIsOrContains('No published release v3 of GameSystem "example-journal" is available to Play.');

        $this->queries->ask(new GetGameSystemSnapshot('example-journal', 3));
    }
}
