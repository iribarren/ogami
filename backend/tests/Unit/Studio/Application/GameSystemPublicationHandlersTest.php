<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\CreateGameSystem;
use App\Studio\Application\CreateGameSystemHandler;
use App\Studio\Application\GameSystemReleaseSummary;
use App\Studio\Application\GameSystemReleaseView;
use App\Studio\Application\GetGameSystem;
use App\Studio\Application\GetGameSystemHandler;
use App\Studio\Application\GetGameSystemRelease;
use App\Studio\Application\GetGameSystemReleaseHandler;
use App\Studio\Application\LibraryImportStandings;
use App\Studio\Application\LibraryPacks;
use App\Studio\Application\ListGameSystemReleases;
use App\Studio\Application\ListGameSystemReleasesHandler;
use App\Studio\Application\OwnedGameSystems;
use App\Studio\Application\PublishGameSystemDraft;
use App\Studio\Application\PublishGameSystemDraftHandler;
use App\Studio\Application\ReleasePublisher;
use App\Studio\Application\RestoreGameSystemRelease;
use App\Studio\Application\RestoreGameSystemReleaseHandler;
use App\Studio\Application\SectionCounts;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\GameSystemRepository;
use App\Studio\Domain\Release\GameSystemDraftUnchanged;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseAlreadyExists;
use App\Studio\Domain\Release\GameSystemReleaseNotFound;
use App\Studio\Domain\Release\GameSystemReleaseRepository;
use App\Studio\Domain\Release\InvalidGameSystemRelease;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;
use App\Tests\Support\Studio\FixedClock;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use App\Tests\Support\Studio\InMemoryGameSystemRepository;
use App\Tests\Support\Studio\InterleavingGameSystemReleaseRepository;
use App\Tests\Support\Studio\ReleaseArrays;
use App\Tests\Support\Studio\SequentialGameSystemIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublishGameSystemDraftHandler::class)]
#[CoversClass(ListGameSystemReleasesHandler::class)]
#[CoversClass(GetGameSystemReleaseHandler::class)]
#[CoversClass(RestoreGameSystemReleaseHandler::class)]
#[CoversClass(ReleasePublisher::class)]
#[CoversClass(SectionCounts::class)]
final class GameSystemPublicationHandlersTest extends TestCase
{
    private InMemoryGameSystemRepository $gameSystems;
    private InMemoryGameSystemReleaseRepository $releases;
    private FixedClock $clock;
    private SequentialGameSystemIdGenerator $ids;
    private int $releaseIds = 0;

    protected function setUp(): void
    {
        $this->gameSystems = new InMemoryGameSystemRepository();
        $this->releases = new InMemoryGameSystemReleaseRepository();
        $this->clock = new FixedClock('2026-10-16T10:00:00+00:00');
        $this->ids = new SequentialGameSystemIdGenerator();
        $this->create('mine', 'ann');
    }

    #[Test]
    public function aDraftIsPublishedAsTheNextVersionWithItsNotes(): void
    {
        $this->publish('ann', 'mine', 'First cut.');
        $this->clock->moveTo('2026-10-17T10:00:00+00:00');
        $this->rename('mine', 'Mine, revised');

        $this->publish('ann', 'mine', null);

        $second = $this->releases->get('mine', 2);
        self::assertInstanceOf(GameSystemRelease::class, $second);
        $name = $second->content()->toArray()['gameSystem'];
        self::assertIsArray($name);
        self::assertSame([null, 2, 'Mine, revised'], [$second->notes(), $second->version(), $name['name']]);
        self::assertSame('First cut.', $this->releases->get('mine', 1)?->notes());
        self::assertEquals(new \DateTimeImmutable('2026-10-17T10:00:00+00:00'), $second->publishedAt());
        $view = (new GetGameSystemHandler($this->owned(), $this->releases, new LibraryImportStandings(new LibraryPacks($this->gameSystems))))(new GetGameSystem('ann', 'mine'));
        self::assertSame([2, false], [$view->latestReleaseVersion, $view->hasUnpublishedChanges]);
    }

    #[Test]
    public function anInvalidDraftIsNotPublished(): void
    {
        $this->owned()->get('ann', 'mine')->restore(['schemaVersion' => 2, 'gameSystem' => ['key' => 'mine', 'name' => 'Mine']], $this->clock->now());

        try {
            $this->publish('ann', 'mine', null);
            self::fail('An invalid draft was published.');
        } catch (InvalidReleaseContent $rejection) {
            self::assertStringStartsWith('oracles: ', $rejection->getMessage());
        }

        self::assertSame(0, $this->releases->count());
    }

    #[Test]
    public function aDraftThatEqualsTheLatestReleaseIsNotPublishedAgain(): void
    {
        $this->publish('ann', 'mine', null);

        try {
            $this->publish('ann', 'mine', 'Again.');
            self::fail('An unchanged draft was published.');
        } catch (GameSystemDraftUnchanged $refusal) {
            self::assertStringContainsString('v1', $refusal->getMessage());
        }

        self::assertSame(1, $this->releases->count());
    }

    #[Test]
    public function badNotesPublishNothing(): void
    {
        foreach (['  ', str_repeat('x', 2001)] as $notes) {
            try {
                $this->publish('ann', 'mine', $notes);
                self::fail('The notes were accepted.');
            } catch (InvalidGameSystemRelease) {
            }
        }

        self::assertSame(0, $this->releases->count());
    }

    #[Test]
    public function aVersionTakenMeanwhileIsReportedAndPublishesNothing(): void
    {
        $racing = new InterleavingGameSystemReleaseRepository(
            $this->releases,
            beforeAdd: static function (GameSystemReleaseRepository $releases, GameSystemRelease $release): void {
                $releases->add(GameSystemRelease::publish(ReleaseId::fromString('other'), $release->content(), $release->version(), $release->publishedAt()));
            },
        );
        $handler = new PublishGameSystemDraftHandler($this->owned(), new ReleasePublisher($racing, $this->clock), $racing);

        try {
            $handler(new PublishGameSystemDraft('ann', 'mine', 'mine-race', null));
            self::fail('Two releases share a version.');
        } catch (GameSystemReleaseAlreadyExists) {
            self::assertNull($this->releases->ofId(ReleaseId::fromString('mine-race')));
            self::assertSame(1, $this->releases->count());
        }
    }

    #[Test]
    public function anotherOwnersOrUnknownGameSystemIsNotFound(): void
    {
        $this->publish('ann', 'mine', null);
        $attempts = [
            fn () => $this->publish('bob', 'mine', null),
            fn () => $this->publish('ann', 'unknown', null),
            fn (): array => (new ListGameSystemReleasesHandler($this->owned(), $this->releases))(new ListGameSystemReleases('bob', 'mine')),
            fn (): GameSystemReleaseView => (new GetGameSystemReleaseHandler($this->owned(), $this->releases))(new GetGameSystemRelease('bob', 'mine', 1)),
            fn () => $this->restore('bob', 'mine', 1),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail('Another owner reached the GameSystem.');
            } catch (GameSystemNotFound) {
            }
        }

        self::assertSame(1, $this->releases->count());
    }

    #[Test]
    public function theHistoryListsTheReleasesNewestFirstWithTheirNotesAndFullHash(): void
    {
        $this->publish('ann', 'mine', 'First cut.');
        $this->rename('mine', 'Mine, revised');
        $this->publish('ann', 'mine', null);
        $this->create('other', 'ann');
        $this->publish('ann', 'other', 'Not mine.');

        $history = (new ListGameSystemReleasesHandler($this->owned(), $this->releases))(new ListGameSystemReleases('ann', 'mine'));

        self::assertSame([[2, null], [1, 'First cut.']], array_map(static fn (GameSystemReleaseSummary $line): array => [$line->version, $line->notes], $history));
        self::assertSame($this->releases->get('mine', 2)?->contentHash(), $history[0]->contentHash);
        self::assertSame(64, \strlen($history[0]->contentHash));
        self::assertSame(2, $history[0]->schemaVersion);
        self::assertEquals(new \DateTimeImmutable('2026-10-16T10:00:00+00:00'), $history[0]->publishedAt);
    }

    #[Test]
    public function aGameSystemWithoutReleasesHasAnEmptyHistory(): void
    {
        self::assertSame([], (new ListGameSystemReleasesHandler($this->owned(), $this->releases))(new ListGameSystemReleases('ann', 'mine')));
    }

    #[Test]
    public function aReleaseIsViewedWithItsNameAndSectionCounts(): void
    {
        $this->create('every-part', 'ann');
        $this->create('example-journal', 'ann');
        $this->releases->add($this->release('every-part', ReleaseArrays::fixture('valid/v2-every-part'), 1));
        $this->releases->add($this->release('example-journal', ReleaseArrays::fixture('valid/contract-doc-example'), 1));

        $v2 = (new GetGameSystemReleaseHandler($this->owned(), $this->releases))(new GetGameSystemRelease('ann', 'every-part', 1));
        $v1 = (new GetGameSystemReleaseHandler($this->owned(), $this->releases))(new GetGameSystemRelease('ann', 'example-journal', 1));

        self::assertSame([1, 2], [$v2->summary->version, $v2->summary->schemaVersion]);
        self::assertSame(64, \strlen($v2->summary->contentHash));
        self::assertSame(array_keys(SectionCounts::of([], 2)), array_keys($v2->sectionCounts));
        self::assertGreaterThan(0, $v2->sectionCounts['sceneTypes']);
        self::assertSame(['oracles', 'flowSteps'], array_keys($v1->sectionCounts));
        self::assertSame([1, 'Example journal'], [$v1->summary->schemaVersion, $v1->name]);
    }

    #[Test]
    public function aReleaseIsViewedWithItsChangesAgainstThePreviousOneAndTheFirstHasNone(): void
    {
        $every = ReleaseArrays::fixture('valid/v2-every-part');
        $this->releases->add($this->release('mine', $every, 1));
        $this->releases->add($this->release('mine', ReleaseArrays::with($every, 'flows.0.name', 'Renamed flow'), 2));
        $this->releases->add($this->release('mine', ReleaseArrays::fixture('valid/contract-doc-example'), 3));
        $handler = new GetGameSystemReleaseHandler($this->owned(), $this->releases);

        $first = $handler(new GetGameSystemRelease('ann', 'mine', 1));
        $second = $handler(new GetGameSystemRelease('ann', 'mine', 2));
        $third = $handler(new GetGameSystemRelease('ann', 'mine', 3));

        self::assertNull($first->changes);
        self::assertNotNull($second->changes);
        self::assertSame([1, 2], [$second->changes['previousVersion'], $second->changes['previousSchemaVersion']]);
        self::assertNotNull($second->changes['sections']);
        self::assertSame(array_keys($second->sectionCounts), array_keys($second->changes['sections']));
        self::assertSame(['name'], $second->changes['sections']['flows']['changed'][0]['fields']);
        self::assertSame([], $second->changes['sections']['trackers']['changed']);
        self::assertSame([2, 2, null], [$third->changes['previousVersion'] ?? null, $third->changes['previousSchemaVersion'] ?? null, $third->changes['sections'] ?? null]);
    }

    #[Test]
    public function anUnknownReleaseVersionIsNotFound(): void
    {
        $this->publish('ann', 'mine', null);

        $this->expectException(GameSystemReleaseNotFound::class);

        (new GetGameSystemReleaseHandler($this->owned(), $this->releases))(new GetGameSystemRelease('ann', 'mine', 2));
    }

    #[Test]
    public function aRestoreReplacesTheDraftWithAReleaseAndKeepsKeyAndOwner(): void
    {
        $this->publish('ann', 'mine', null);
        $this->rename('mine', 'Mine, revised');
        $this->clock->moveTo('2026-10-18T10:00:00+00:00');

        $this->restore('ann', 'mine', 1);

        $draft = $this->owned()->get('ann', 'mine');
        self::assertSame(['mine', 'Mine'], [$draft->key(), $draft->name()]);
        self::assertEquals(new \DateTimeImmutable('2026-10-18T10:00:00+00:00'), $draft->updatedAt());
        $view = (new GetGameSystemHandler($this->owned(), $this->releases, new LibraryImportStandings(new LibraryPacks($this->gameSystems))))(new GetGameSystem('ann', 'mine'));
        self::assertFalse($view->hasUnpublishedChanges, 'The restored latest release leaves nothing unpublished.');
    }

    #[Test]
    public function aRestoreOfAnUnknownVersionLeavesTheDraftUnchanged(): void
    {
        $this->rename('mine', 'Mine, revised');
        $before = $this->owned()->get('ann', 'mine')->document();

        try {
            $this->restore('ann', 'mine', 3);
            self::fail('An unknown release was restored.');
        } catch (GameSystemReleaseNotFound) {
        }

        self::assertSame($before, $this->owned()->get('ann', 'mine')->document());
    }

    #[Test]
    public function aRestoreSavedAgainMeanwhileIsReported(): void
    {
        $this->publish('ann', 'mine', null);
        $stale = new readonly class($this->gameSystems) implements GameSystemRepository {
            public function __construct(private GameSystemRepository $inner)
            {
            }

            public function add(\App\Studio\Domain\GameSystem\GameSystem $gameSystem): void
            {
                $this->inner->add($gameSystem);
            }

            public function save(\App\Studio\Domain\GameSystem\GameSystem $gameSystem): void
            {
                throw GameSystemModifiedConcurrently::withKey($gameSystem->key());
            }

            public function ofKey(string $key): ?\App\Studio\Domain\GameSystem\GameSystem
            {
                return $this->inner->ofKey($key);
            }

            public function ofOwner(string $ownerId): array
            {
                return $this->inner->ofOwner($ownerId);
            }

            public function libraryPacks(): array
            {
                return $this->inner->libraryPacks();
            }

            public function remove(\App\Studio\Domain\GameSystem\GameSystem $gameSystem): void
            {
                $this->inner->remove($gameSystem);
            }
        };

        $this->expectException(GameSystemModifiedConcurrently::class);

        (new RestoreGameSystemReleaseHandler(new OwnedGameSystems($stale), $stale, $this->releases, $this->clock))(new RestoreGameSystemRelease('ann', 'mine', 1));
    }

    private function owned(): OwnedGameSystems
    {
        return new OwnedGameSystems($this->gameSystems);
    }

    private function create(string $key, string $owner): void
    {
        (new CreateGameSystemHandler($this->gameSystems, $this->releases, $this->clock))(new CreateGameSystem($this->ids->generate()->toString(), $owner, $key, 'every-part' === $key ? 'Every part' : ucfirst($key), null, null, null));
    }

    private function rename(string $key, string $name): void
    {
        $draft = $this->owned()->get('ann', $key);
        $draft->restore(ReleaseArrays::with($draft->document(), 'gameSystem.name', $name), $this->clock->now());
    }

    private function publish(string $owner, string $key, ?string $notes): void
    {
        $handler = new PublishGameSystemDraftHandler($this->owned(), new ReleasePublisher($this->releases, $this->clock), $this->releases);
        $handler(new PublishGameSystemDraft($owner, $key, 'release-'.++$this->releaseIds, $notes));
    }

    private function restore(string $owner, string $key, int $version): void
    {
        (new RestoreGameSystemReleaseHandler($this->owned(), $this->gameSystems, $this->releases, $this->clock))(new RestoreGameSystemRelease($owner, $key, $version));
    }

    /**
     * @param array<mixed> $document
     */
    private function release(string $key, array $document, int $version): GameSystemRelease
    {
        $document = ReleaseArrays::with($document, 'gameSystem.key', $key);

        return GameSystemRelease::publish(ReleaseId::fromString('fixture-'.++$this->releaseIds), ReleaseContent::fromArray($document), $version, $this->clock->now());
    }
}
