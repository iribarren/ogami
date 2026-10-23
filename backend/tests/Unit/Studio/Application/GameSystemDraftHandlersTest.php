<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\CreateGameSystem;
use App\Studio\Application\CreateGameSystemHandler;
use App\Studio\Application\DiscardGameSystem;
use App\Studio\Application\DiscardGameSystemHandler;
use App\Studio\Application\EditGameSystemMetadata;
use App\Studio\Application\EditGameSystemMetadataHandler;
use App\Studio\Application\GameSystemPublication;
use App\Studio\Application\GameSystemSummary;
use App\Studio\Application\GameSystemValidation;
use App\Studio\Application\GameSystemValidationError;
use App\Studio\Application\GameSystemView;
use App\Studio\Application\GetGameSystem;
use App\Studio\Application\GetGameSystemHandler;
use App\Studio\Application\LibraryImportStandings;
use App\Studio\Application\LibraryPacks;
use App\Studio\Application\ListMyGameSystems;
use App\Studio\Application\ListMyGameSystemsHandler;
use App\Studio\Application\OwnedGameSystems;
use App\Studio\Application\PublishedReleaseNotFound;
use App\Studio\Application\ReleasePublisher;
use App\Studio\Application\ValidateGameSystemDraft;
use App\Studio\Application\ValidateGameSystemDraftHandler;
use App\Studio\Domain\GameSystem\GameSystemHasReleases;
use App\Studio\Domain\GameSystem\GameSystemKeyTaken;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\InvalidGameSystemMetadata;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;
use App\Tests\Support\Studio\FixedClock;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use App\Tests\Support\Studio\InMemoryGameSystemRepository;
use App\Tests\Support\Studio\ReleaseArrays;
use App\Tests\Support\Studio\SequentialGameSystemIdGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CreateGameSystemHandler::class)]
#[CoversClass(EditGameSystemMetadataHandler::class)]
#[CoversClass(DiscardGameSystemHandler::class)]
#[CoversClass(ListMyGameSystemsHandler::class)]
#[CoversClass(GetGameSystemHandler::class)]
#[CoversClass(ValidateGameSystemDraftHandler::class)]
#[CoversClass(OwnedGameSystems::class)]
#[CoversClass(GameSystemPublication::class)]
final class GameSystemDraftHandlersTest extends TestCase
{
    private InMemoryGameSystemRepository $gameSystems;
    private InMemoryGameSystemReleaseRepository $releases;
    private FixedClock $clock;
    private SequentialGameSystemIdGenerator $ids;

    protected function setUp(): void
    {
        $this->gameSystems = new InMemoryGameSystemRepository();
        $this->releases = new InMemoryGameSystemReleaseRepository();
        $this->clock = new FixedClock('2026-10-15T10:00:00+00:00');
        $this->ids = new SequentialGameSystemIdGenerator();
    }

    #[Test]
    public function aBlankDraftIsCreatedForItsOwner(): void
    {
        $this->create('my-system', 'My system', 'ann');

        $view = $this->get('ann', 'my-system');
        self::assertSame(['My system', 2, null, true], [$view->name, $view->schemaVersion, $view->latestReleaseVersion, $view->hasUnpublishedChanges]);
        self::assertSame(array_fill_keys(['oracleTables', 'likelihoodOracles', 'trackers', 'factSlots', 'tags', 'sceneTypes', 'flows', 'sheetFields', 'checks'], 0), $view->sectionCounts);
    }

    #[Test]
    public function aCopyTakesTheLatestOrAGivenVersionOfARelease(): void
    {
        $this->publish(ReleaseArrays::fixture('valid/v2-every-part'));
        $this->publish(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-every-part'), 'gameSystem.name', 'Every part 2'));

        $this->create('copy-latest', 'Copy latest', 'ann', 'every-part');
        $this->create('copy-first', 'Copy first', 'ann', 'every-part', 1);

        $latest = $this->get('ann', 'copy-latest');
        self::assertSame(['Copy latest', 2], [$latest->name, $latest->schemaVersion]);
        self::assertSame(2, $this->get('ann', 'copy-first')->schemaVersion);
        self::assertGreaterThan(0, $latest->sectionCounts['sceneTypes']);
        self::assertNull($latest->latestReleaseVersion);
    }

    #[Test]
    public function aGivenDescriptionReplacesTheSourceOneOnACopyAndNullKeepsIt(): void
    {
        $this->publish(ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-minimal'), 'gameSystem.description', 'Source text'));

        $this->create('kept', 'Kept', 'ann', 'minimal');
        $this->create('replaced', 'Replaced', 'ann', 'minimal', null, 'My text');

        self::assertSame('Source text', $this->get('ann', 'kept')->description);
        self::assertSame('My text', $this->get('ann', 'replaced')->description);
    }

    #[Test]
    public function aCopyWithAnInvalidDescriptionIsRefusedAndCreatesNothing(): void
    {
        $this->publish(ReleaseArrays::fixture('valid/v2-minimal'));

        try {
            $this->create('copy', 'Copy', 'ann', 'minimal', null, str_repeat('x', 100000));
            self::fail('The draft was created.');
        } catch (InvalidGameSystemMetadata $refusal) {
            self::assertSame('description', $refusal->field);
            self::assertSame([], $this->list('ann'));
        }
    }

    #[Test]
    public function aCopyOfASchemaVersion1ReleaseCountsOraclesAndFlowSteps(): void
    {
        $this->publish(ReleaseArrays::fixture('valid/contract-doc-example'));

        $this->create('v1-copy', 'V1 copy', 'ann', 'example-journal');

        $view = $this->get('ann', 'v1-copy');
        self::assertSame(1, $view->schemaVersion);
        self::assertSame(['oracles', 'flowSteps'], array_keys($view->sectionCounts));
    }

    #[Test]
    public function aMissingSourceIsRefused(): void
    {
        $this->publish(ReleaseArrays::fixture('valid/v2-minimal'));

        foreach ([['nothing', null], ['minimal', 7]] as [$sourceKey, $version]) {
            try {
                $this->create('new-key', 'New', 'ann', $sourceKey, $version);
                self::fail('The draft was created.');
            } catch (PublishedReleaseNotFound) {
                self::assertSame([], $this->list('ann'));
            }
        }
    }

    #[Test]
    public function aKeyUsedByAGameSystemOrAReleaseIsRefused(): void
    {
        $this->publish(ReleaseArrays::fixture('valid/v2-minimal'));
        $this->create('mine', 'Mine', 'ann');

        foreach (['mine', 'minimal'] as $key) {
            try {
                $this->create($key, 'Again', 'bob');
                self::fail('The draft was created.');
            } catch (GameSystemKeyTaken) {
                self::assertSame([], $this->list('bob'));
            }
        }
    }

    #[Test]
    public function invalidInputIsRefusedWithADomainError(): void
    {
        foreach ([['Bad Key', ''], ['ok-key', ' ']] as [$key, $name]) {
            try {
                $this->create($key, $name, 'ann');
                self::fail('The draft was created.');
            } catch (InvalidGameSystemMetadata $refusal) {
                self::assertSame('' === $name ? 'key' : 'name', $refusal->field);
            }
        }

        $this->expectException(InvalidGameSystemMetadata::class);
        $this->expectExceptionMessageIsOrContains('sourceVersion');

        $this->create('ok-key', 'Ok', 'ann', null, 1);
    }

    #[Test]
    public function metadataIsEditedByItsOwner(): void
    {
        $this->create('my-system', 'My system', 'ann');
        $this->clock->moveTo('2026-10-16T10:00:00+00:00');

        $this->edit('ann', 'my-system', 'Renamed', 'Text', ['notice' => 'By ann'], 'noir');

        $view = $this->get('ann', 'my-system');
        self::assertSame(['Renamed', 'Text', ['notice' => 'By ann'], 'noir'], [$view->name, $view->description, $view->credits, $view->theme]);
        self::assertEquals(new \DateTimeImmutable('2026-10-16T10:00:00+00:00'), $view->updatedAt);
        self::assertEquals(new \DateTimeImmutable('2026-10-15T10:00:00+00:00'), $view->createdAt);
    }

    #[Test]
    public function invalidMetadataChangesNothing(): void
    {
        $this->create('my-system', 'My system', 'ann');

        try {
            $this->edit('ann', 'my-system', 'Renamed', null, ['notice' => 'N', 'url' => 'http://x.org'], null);
            self::fail('The metadata was accepted.');
        } catch (InvalidGameSystemMetadata $refusal) {
            self::assertSame('credits', $refusal->field);
        }

        self::assertSame('My system', $this->get('ann', 'my-system')->name);
    }

    #[Test]
    public function anotherOwnersGameSystemIsNotFound(): void
    {
        $this->create('private-one', 'Private', 'ann');
        $attempts = [
            fn (): GameSystemView => $this->get('bob', 'private-one'),
            fn () => $this->edit('bob', 'private-one', 'Mine now', null, null, null),
            fn () => (new DiscardGameSystemHandler($this->owned(), $this->gameSystems, $this->releases))(new DiscardGameSystem('bob', 'private-one')),
            fn (): GameSystemValidation => (new ValidateGameSystemDraftHandler($this->owned()))(new ValidateGameSystemDraft('bob', 'private-one')),
            fn (): GameSystemView => $this->get('ann', 'unknown'),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail('Another owner reached the GameSystem.');
            } catch (GameSystemNotFound) {
            }
        }

        self::assertSame('Private', $this->get('ann', 'private-one')->name);
        self::assertSame([], $this->list('bob'));
    }

    #[Test]
    public function aNeverPublishedDraftIsDiscarded(): void
    {
        $this->create('my-system', 'My system', 'ann');

        $this->discard('ann', 'my-system');

        self::assertSame([], $this->list('ann'));
    }

    #[Test]
    public function aPublishedGameSystemCannotBeDiscarded(): void
    {
        $this->create('my-system', 'My system', 'ann');
        $this->publish($this->draftDocument('ann', 'my-system'));

        try {
            $this->discard('ann', 'my-system');
            self::fail('The draft was discarded.');
        } catch (GameSystemHasReleases) {
            self::assertSame('My system', $this->get('ann', 'my-system')->name);
        }
    }

    #[Test]
    public function theListHoldsTheOwnersGameSystemsNewestUpdatedFirst(): void
    {
        $this->create('older', 'Older', 'ann');
        $this->clock->moveTo('2026-10-15T12:00:00+00:00');
        $this->create('newer', 'Newer', 'ann');
        $this->create('theirs', 'Theirs', 'bob');

        $list = $this->list('ann');

        self::assertSame(['newer', 'older'], array_map(static fn (GameSystemSummary $summary): string => $summary->key, $list));
        self::assertSame(['Newer', 2, null, true], [$list[0]->name, $list[0]->schemaVersion, $list[0]->latestReleaseVersion, $list[0]->hasUnpublishedChanges]);
    }

    #[Test]
    public function unpublishedChangesFollowTheLatestReleaseContent(): void
    {
        $this->create('my-system', 'My system', 'ann');
        $this->publish($this->draftDocument('ann', 'my-system'));

        $published = $this->get('ann', 'my-system');
        self::assertSame([1, false], [$published->latestReleaseVersion, $published->hasUnpublishedChanges]);

        $this->edit('ann', 'my-system', 'Renamed', null, null, null);
        self::assertTrue($this->get('ann', 'my-system')->hasUnpublishedChanges);

        $this->edit('ann', 'my-system', 'My system', null, null, null);
        self::assertFalse($this->get('ann', 'my-system')->hasUnpublishedChanges);
    }

    #[Test]
    public function anInvalidDraftHasUnpublishedChangesAndReportsItsFirstError(): void
    {
        $this->publish(ReleaseArrays::fixture('valid/v2-minimal'));
        $this->create('my-copy', 'My copy', 'ann', 'minimal');
        $draft = $this->gameSystems->ofKey('my-copy');
        self::assertNotNull($draft);
        $draft->restore(['schemaVersion' => 2, 'gameSystem' => ['key' => 'x', 'name' => 'Broken']], $this->clock->now());

        $validation = $this->validate('ann', 'my-copy');

        self::assertFalse($validation->valid);
        self::assertEquals([new GameSystemValidationError('oracles', 'required')], $validation->errors);
        self::assertSame([], $validation->warnings);
        self::assertTrue($this->get('ann', 'my-copy')->hasUnpublishedChanges);
    }

    #[Test]
    public function anInvalidDraftReportsEveryErrorWithItsPathAndReason(): void
    {
        $this->create('my-copy', 'My copy', 'ann');
        $draft = $this->gameSystems->ofKey('my-copy');
        self::assertNotNull($draft);
        $document = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-contract-doc-example'), 'trackers.0.name', '');
        $draft->restore(ReleaseArrays::with($document, 'theme', 'pastel'), $this->clock->now());

        $validation = $this->validate('ann', 'my-copy');

        self::assertFalse($validation->valid);
        self::assertSame(['trackers[0].name', 'theme'], array_map(static fn (GameSystemValidationError $error): string => $error->path, $validation->errors));
        self::assertStringNotContainsString('trackers[0].name', $validation->errors[0]->message);
        self::assertStringStartsWith('must be one of', $validation->errors[1]->message);
    }

    #[Test]
    public function aValidDraftIsValidWithItsWarnings(): void
    {
        $this->publish(ReleaseArrays::fixture('valid/v2-forced-scene-without-relief'));
        $this->create('warned', 'Warned', 'ann', 'warning-heist');
        $this->create('plain', 'Plain', 'ann');

        self::assertEquals(new GameSystemValidation(true, [], []), $this->validate('ann', 'plain'));
        $warned = $this->validate('ann', 'warned');
        self::assertTrue($warned->valid);
        self::assertSame([], $warned->errors);
        self::assertCount(1, $warned->warnings);
    }

    private function owned(): OwnedGameSystems
    {
        return new OwnedGameSystems($this->gameSystems);
    }

    private function create(string $key, string $name, string $owner, ?string $sourceKey = null, ?int $sourceVersion = null, ?string $description = null): void
    {
        (new CreateGameSystemHandler($this->gameSystems, $this->releases, $this->clock))(new CreateGameSystem($this->ids->generate()->toString(), $owner, $key, $name, $description, $sourceKey, $sourceVersion));
    }

    /**
     * @param array<mixed>|null $credits
     */
    private function edit(string $owner, string $key, string $name, ?string $description, ?array $credits, ?string $theme): void
    {
        (new EditGameSystemMetadataHandler($this->owned(), $this->gameSystems, $this->clock))(new EditGameSystemMetadata($owner, $key, $name, $description, $credits, $theme));
    }

    private function discard(string $owner, string $key): void
    {
        (new DiscardGameSystemHandler($this->owned(), $this->gameSystems, $this->releases))(new DiscardGameSystem($owner, $key));
    }

    private function get(string $owner, string $key): GameSystemView
    {
        return (new GetGameSystemHandler($this->owned(), $this->releases, new LibraryImportStandings(new LibraryPacks($this->gameSystems))))(new GetGameSystem($owner, $key));
    }

    /**
     * @return list<GameSystemSummary>
     */
    private function list(string $owner): array
    {
        return (new ListMyGameSystemsHandler($this->gameSystems, $this->releases, new LibraryImportStandings(new LibraryPacks($this->gameSystems))))(new ListMyGameSystems($owner));
    }

    private function validate(string $owner, string $key): GameSystemValidation
    {
        return (new ValidateGameSystemDraftHandler($this->owned()))(new ValidateGameSystemDraft($owner, $key));
    }

    /**
     * @param array<mixed> $document
     */
    private function publish(array $document): void
    {
        new ReleasePublisher($this->releases, $this->clock)->publish(ReleaseId::fromString('release-'.$this->releases->count()), ReleaseContent::fromArray($document), null, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function draftDocument(string $owner, string $key): array
    {
        return $this->owned()->get($owner, $key)->document();
    }
}
