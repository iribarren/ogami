<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Application\FlowSummaryView;
use App\Play\Application\GameSystemSummary;
use App\Play\Application\ListGameSystems;
use App\Play\Application\ListGameSystemsHandler;
use App\Play\Domain\GameSystem\Credits;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Play\Infrastructure\GameSystem\StudioPublishedGameSystemReleases;
use App\Shared\Application\Bus\Query;
use App\Shared\Application\Bus\QueryBus;
use App\Studio\Application\ListPublishedGameSystems;
use App\Studio\Application\ListPublishedGameSystemsHandler;
use App\Studio\Application\PublishedGameSystemSummary;
use App\Studio\Application\PublishedReleaseNotFound;
use App\Studio\Application\PublishedReleaseUnreadable;
use App\Studio\Application\PublishedReleaseView;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseRepository;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[Test]
    public function listingReadsTheRepositoryOnceNoMatterHowManyGameSystemsThereAre(): void
    {
        $repository = new CountingGameSystemReleaseRepository();
        $repository->add($this->release('r-1', 'valid/v2-minimal', 'alpha', 1));
        $repository->add($this->release('r-2', 'valid/v2-minimal', 'beta', 1));
        $repository->add($this->release('r-3', 'valid/v2-minimal', 'gamma', 1));

        $summaries = $this->listing($repository)(new ListGameSystems());

        self::assertSame(['alpha', 'beta', 'gamma'], array_map(static fn (GameSystemSummary $summary): string => $summary->gameSystemKey, $summaries));
        self::assertSame(['latestOfEachKey'], $repository->calls);
    }

    #[Test]
    public function aListedGameSystemCarriesTheFlowsOfItsRelease(): void
    {
        $repository = new InMemoryGameSystemReleaseRepository();
        $repository->add($this->release('r-1', 'examples/cpr-heist', 'cpr-heist', 1));

        $summaries = $this->listing($repository)(new ListGameSystems());

        self::assertCount(1, $summaries);
        self::assertSame(['heist'], array_map(static fn (FlowSummaryView $flow): string => $flow->key, $summaries[0]->flows));
        self::assertNull($summaries[0]->credits);
    }

    #[Test]
    public function aListedGameSystemCarriesTheCreditsOfItsRelease(): void
    {
        $repository = new InMemoryGameSystemReleaseRepository();
        $repository->add($this->release('r-1', 'valid/v2-credits', 'minimal', 1));

        $summaries = $this->listing($repository)(new ListGameSystems());

        self::assertEquals(
            new Credits(
                'Night Job is unofficial content provided under the Homebrew Content Policy of R. Talsorian Games.',
                'R. Talsorian Games Homebrew Content Policy',
                'https://rtalsoriangames.com/homebrew-content-policy/',
            ),
            $summaries[0]->credits,
        );
    }

    #[Test]
    public function aSchemaVersion1ReleaseIsListedWithoutFlowsOrCredits(): void
    {
        $repository = new InMemoryGameSystemReleaseRepository();
        $repository->add($this->release('r-1', 'valid/contract-doc-example', 'contract-doc-example', 1));

        $summaries = $this->listing($repository)(new ListGameSystems());

        self::assertCount(1, $summaries);
        self::assertSame('contract-doc-example', $summaries[0]->gameSystemKey);
        self::assertSame([], $summaries[0]->flows);
        self::assertNull($summaries[0]->credits);
    }

    /**
     * @return iterable<string, array{PublishedReleaseView}>
     */
    public static function unreadableViews(): iterable
    {
        yield 'unsupported schema version' => [ReleaseViews::of(ReleaseViews::fixtureContent('valid/v2-minimal'), 1, 99)];
        yield 'broken content' => [ReleaseViews::of(['schemaVersion' => 2, 'gameSystem' => ['key' => 'free-journal', 'name' => 'Free journal'], 'oracles' => 'broken'])];
    }

    /**
     * The catalog still lists a release Play cannot read, as before releases had Flows; creating a
     * campaign with it fails as it always did.
     */
    #[Test]
    #[DataProvider('unreadableViews')]
    public function aLatestReleaseThatCannotBeReadIsListedWithoutFlowsOrCredits(PublishedReleaseView $view): void
    {
        $queries = new readonly class($view) implements QueryBus {
            public function __construct(private PublishedReleaseView $view)
            {
            }

            /**
             * @template TResult
             *
             * @param Query<TResult> $query
             *
             * @return TResult
             */
            public function ask(Query $query): mixed
            {
                // @phpstan-ignore return.type
                return [new PublishedGameSystemSummary('free-journal', 'Free journal', 'A journal.', 1, $this->view->publishedAt, $this->view)];
            }
        };

        $summaries = new StudioPublishedGameSystemReleases($queries, new GameSystemReleaseTranslator())->latest();

        self::assertEquals([new GameSystemSummary('free-journal', 'Free journal', 'A journal.', 1)], $summaries);
    }

    #[Test]
    public function aSummaryWithoutAReleaseViewIsListedWithoutFlowsOrCredits(): void
    {
        $queries = new readonly class implements QueryBus {
            /**
             * @template TResult
             *
             * @param Query<TResult> $query
             *
             * @return TResult
             */
            public function ask(Query $query): mixed
            {
                // @phpstan-ignore return.type
                return [new PublishedGameSystemSummary('free-journal', 'free-journal', null, 2, new \DateTimeImmutable('2026-10-05T10:00:00+00:00'), null)];
            }
        };

        $summaries = new StudioPublishedGameSystemReleases($queries, new GameSystemReleaseTranslator())->latest();

        self::assertEquals([new GameSystemSummary('free-journal', 'free-journal', null, 2)], $summaries);
    }

    private function release(string $id, string $fixture, string $key, int $version): GameSystemRelease
    {
        $content = ReleaseViews::fixtureContent($fixture);
        \assert(\is_array($content['gameSystem']));
        $content['gameSystem']['key'] = $key;

        return GameSystemRelease::publish(ReleaseId::fromString($id), ReleaseContent::fromArray($content), $version, new \DateTimeImmutable('2026-10-05T10:00:00+00:00'));
    }

    private function listing(GameSystemReleaseRepository $repository): ListGameSystemsHandler
    {
        $queries = new readonly class(new ListPublishedGameSystemsHandler($repository)) implements QueryBus {
            public function __construct(private ListPublishedGameSystemsHandler $handler)
            {
            }

            /**
             * @template TResult
             *
             * @param Query<TResult> $query
             *
             * @return TResult
             */
            public function ask(Query $query): mixed
            {
                // @phpstan-ignore return.type
                return $query instanceof ListPublishedGameSystems ? ($this->handler)($query) : throw new \LogicException('Listing must only ask ListPublishedGameSystems.');
            }
        };

        return new ListGameSystemsHandler(new StudioPublishedGameSystemReleases($queries, new GameSystemReleaseTranslator()));
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

/**
 * Records which repository methods were called, to show listing does not read per GameSystem.
 */
final class CountingGameSystemReleaseRepository implements GameSystemReleaseRepository
{
    /** @var list<string> */
    public array $calls = [];

    private readonly InMemoryGameSystemReleaseRepository $inner;

    public function __construct()
    {
        $this->inner = new InMemoryGameSystemReleaseRepository();
    }

    public function add(GameSystemRelease $release): void
    {
        $this->inner->add($release);
    }

    public function latestFor(string $gameSystemKey): ?GameSystemRelease
    {
        $this->calls[] = 'latestFor';

        return $this->inner->latestFor($gameSystemKey);
    }

    public function allFor(string $gameSystemKey): array
    {
        $this->calls[] = 'allFor';

        return $this->inner->allFor($gameSystemKey);
    }

    public function latestOfEachKey(): array
    {
        $this->calls[] = 'latestOfEachKey';

        return $this->inner->latestOfEachKey();
    }

    public function get(string $gameSystemKey, int $version): ?GameSystemRelease
    {
        $this->calls[] = 'get';

        return $this->inner->get($gameSystemKey, $version);
    }

    public function ofId(ReleaseId $id): ?GameSystemRelease
    {
        $this->calls[] = 'ofId';

        return $this->inner->ofId($id);
    }
}
