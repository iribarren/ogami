<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Randomness\Application\OracleTableResultView;
use App\Studio\Application\CreateGameSystem;
use App\Studio\Application\CreateGameSystemHandler;
use App\Studio\Application\DeleteLikelihoodOracle;
use App\Studio\Application\DeleteLikelihoodOracleHandler;
use App\Studio\Application\DeleteOracleTable;
use App\Studio\Application\DeleteOracleTableHandler;
use App\Studio\Application\DiscardGameSystem;
use App\Studio\Application\DiscardGameSystemHandler;
use App\Studio\Application\EditGameSystemMetadata;
use App\Studio\Application\EditGameSystemMetadataHandler;
use App\Studio\Application\GameSystemOraclesView;
use App\Studio\Application\GameSystemReleaseSummary;
use App\Studio\Application\GameSystemReleaseView;
use App\Studio\Application\GetGameSystem;
use App\Studio\Application\GetGameSystemHandler;
use App\Studio\Application\GetGameSystemOracles;
use App\Studio\Application\GetGameSystemOraclesHandler;
use App\Studio\Application\GetGameSystemRelease;
use App\Studio\Application\GetGameSystemReleaseHandler;
use App\Studio\Application\GetPublishedRelease;
use App\Studio\Application\GetPublishedReleaseHandler;
use App\Studio\Application\LikelihoodTestView;
use App\Studio\Application\ListGameSystemReleases;
use App\Studio\Application\ListGameSystemReleasesHandler;
use App\Studio\Application\ListMyGameSystems;
use App\Studio\Application\ListMyGameSystemsHandler;
use App\Studio\Application\OracleView;
use App\Studio\Application\OwnedGameSystems;
use App\Studio\Application\PublishedReleaseView;
use App\Studio\Application\PublishGameSystemDraft;
use App\Studio\Application\PublishGameSystemDraftHandler;
use App\Studio\Application\PublishGameSystemRelease;
use App\Studio\Application\PublishGameSystemReleaseHandler;
use App\Studio\Application\ReleasePublisher;
use App\Studio\Application\RestoreGameSystemRelease;
use App\Studio\Application\RestoreGameSystemReleaseHandler;
use App\Studio\Application\SaveOracleTable;
use App\Studio\Application\SaveOracleTableHandler;
use App\Studio\Application\TestAskLikelihoodOracle;
use App\Studio\Application\TestAskLikelihoodOracleHandler;
use App\Studio\Application\TestRollOracleTable;
use App\Studio\Application\TestRollOracleTableHandler;
use App\Studio\Application\ValidateGameSystemDraft;
use App\Studio\Application\ValidateGameSystemDraftHandler;
use App\Studio\Domain\GameSystem\GameSystemHasReleases;
use App\Studio\Domain\GameSystem\GameSystemKeyTaken;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\InvalidGameSystemMetadata;
use App\Studio\Domain\GameSystem\OracleInUse;
use App\Studio\Domain\GameSystem\OracleKeyTaken;
use App\Studio\Domain\GameSystem\OracleNotFound;
use App\Studio\Domain\Release\GameSystemDraftUnchanged;
use App\Studio\Domain\Release\GameSystemReleaseNotFound;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use App\Tests\Support\Studio\FixedClock;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use App\Tests\Support\Studio\InMemoryGameSystemRepository;
use App\Tests\Support\Studio\ReleaseArrays;
use App\Tests\Support\Studio\SequentialGameSystemIdGenerator;
use App\Tests\Support\Studio\SequentialReleaseIdGenerator;
use App\Tests\Unit\Studio\Application\RandomnessQueryBus;
use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use PHPUnit\Framework\Assert;

/**
 * Publishing GameSystem releases and authoring GameSystem drafts in Studio, on in-memory fakes (no kernel).
 */
final class StudioContext implements Context
{
    private readonly InMemoryGameSystemReleaseRepository $releases;
    private readonly SequentialReleaseIdGenerator $ids;
    private readonly PublishGameSystemReleaseHandler $publish;
    private readonly GetPublishedReleaseHandler $getRelease;

    /** @var array<string, mixed> */
    private array $releaseFile = [];
    private ?ReleaseId $lastPublishId = null;
    private ?InvalidReleaseContent $rejection = null;
    private readonly InMemoryGameSystemRepository $drafts;
    private readonly SequentialGameSystemIdGenerator $draftIds;
    private readonly FixedClock $clock;
    private readonly OwnedGameSystems $owned;
    private ?\Throwable $draftRefusal = null;
    private ScriptedRandomNumberGenerator $dice;
    private ?OracleTableResultView $tableRoll = null;
    private ?LikelihoodTestView $likelihoodTest = null;
    /** @var array<string, mixed> */
    private array $documentBeforeTest = [];

    public function __construct()
    {
        $this->releases = new InMemoryGameSystemReleaseRepository();
        $this->ids = new SequentialReleaseIdGenerator();
        $this->clock = new FixedClock();
        $this->drafts = new InMemoryGameSystemRepository();
        $this->publish = new PublishGameSystemReleaseHandler(new ReleasePublisher($this->releases, $this->clock), $this->drafts);
        $this->draftIds = new SequentialGameSystemIdGenerator();
        $this->owned = new OwnedGameSystems($this->drafts);
        $this->getRelease = new GetPublishedReleaseHandler($this->releases);
        $this->dice = new ScriptedRandomNumberGenerator();
    }

    #[Given('a GameSystem release file for the GameSystem :key named :name')]
    public function aReleaseFile(string $key, string $name): void
    {
        $this->releaseFile = [
            'schemaVersion' => 1,
            'gameSystem' => ['key' => $key, 'name' => $name],
            'oracles' => [
                'tables' => [['key' => 'action', 'name' => 'Action', 'entries' => [['text' => 'Seek'], ['text' => 'Guard']]]],
                'likelihood' => [],
            ],
            'flow' => ['steps' => [['key' => 'set-scene', 'title' => 'Set the scene']]],
            'sheet' => [],
            'checks' => [],
        ];
    }

    #[Given('the GameSystem is renamed :name in the release file')]
    public function theGameSystemIsRenamed(string $name): void
    {
        $this->releaseFile['gameSystem'] = ['key' => $this->gameSystemKey(), 'name' => $name];
    }

    #[Given('the release file has the flow step key :key')]
    public function theReleaseFileHasTheFlowStepKey(string $key): void
    {
        $this->releaseFile['flow'] = ['steps' => [['key' => $key, 'title' => 'Set the scene']]];
    }

    #[Given('I published the GameSystem release')]
    public function iPublishedTheRelease(): void
    {
        $this->publish(false);
        Assert::assertNull($this->rejection, $this->rejection?->getMessage() ?? '');
    }

    #[When('I publish the GameSystem release')]
    public function iPublishTheRelease(): void
    {
        $this->publish(false);
    }

    #[When('I publish the GameSystem release only if it changed')]
    public function iPublishTheReleaseOnlyIfItChanged(): void
    {
        $this->publish(true);
    }

    #[Then('/^the GameSystem "(?P<key>[^"]+)" has (?P<count>\d+) releases?$/')]
    public function theGameSystemHasReleases(string $key, int $count): void
    {
        // Versions of a GameSystem run 1, 2, 3… so the latest version is the number of releases.
        Assert::assertSame($count, $this->releases->latestFor($key)?->version() ?? 0);
    }

    #[Then('the latest release of :key is version :version named :name')]
    public function theLatestReleaseIs(string $key, int $version, string $name): void
    {
        $this->assertRelease($this->view($key, null), $version, $name);
    }

    #[Then('release version :version of :key is still named :name')]
    public function theReleaseVersionIsNamed(int $version, string $key, string $name): void
    {
        $this->assertRelease($this->view($key, $version), $version, $name);
    }

    #[Then('nothing is published')]
    public function nothingIsPublished(): void
    {
        Assert::assertNull($this->rejection, $this->rejection?->getMessage() ?? '');
        Assert::assertNotNull($this->lastPublishId);
        Assert::assertNull($this->releases->ofId($this->lastPublishId));
    }

    #[Then('the GameSystem release is rejected at :path')]
    public function theReleaseIsRejectedAt(string $path): void
    {
        Assert::assertInstanceOf(InvalidReleaseContent::class, $this->rejection);
        Assert::assertStringStartsWith($path.': ', $this->rejection->getMessage());
    }

    private function publish(bool $onlyIfChanged): void
    {
        $this->rejection = null;
        $this->lastPublishId = $this->ids->generate();

        try {
            ($this->publish)(new PublishGameSystemRelease($this->lastPublishId->toString(), $this->releaseFile, $onlyIfChanged));
        } catch (InvalidReleaseContent $rejection) {
            $this->rejection = $rejection;
        }
    }

    private function view(string $key, ?int $version): PublishedReleaseView
    {
        return ($this->getRelease)(new GetPublishedRelease($key, $version));
    }

    private function assertRelease(PublishedReleaseView $view, int $version, string $name): void
    {
        Assert::assertSame($version, $view->version);
        Assert::assertIsArray($view->content['gameSystem']);
        Assert::assertSame($name, $view->content['gameSystem']['name']);
    }

    private function gameSystemKey(): string
    {
        $gameSystem = $this->releaseFile['gameSystem'];
        Assert::assertIsArray($gameSystem);
        Assert::assertIsString($gameSystem['key']);

        return $gameSystem['key'];
    }

    #[Given('the game manager :user')]
    public function theGameManager(string $user): void
    {
        // Users are only ids to Studio; naming one is enough.
    }

    #[Given('a published GameSystem release :key named :name')]
    public function aPublishedRelease(string $key, string $name): void
    {
        $this->aReleaseFile($key, $name);
        $this->iPublishedTheRelease();
    }

    #[Given('/^(?P<user>\w+) created the (?:blank )?GameSystem "(?P<key>[^"]+)" named "(?P<name>[^"]+)"(?: as a copy of "(?P<source>[^"]+)")?$/')]
    #[When('/^(?P<user>\w+) creates the (?:blank )?GameSystem "(?P<key>[^"]+)" named "(?P<name>[^"]+)"(?: as a copy of "(?P<source>[^"]+)")?$/')]
    public function creates(string $user, string $key, string $name, ?string $source = null): void
    {
        $this->attempt(fn () => (new CreateGameSystemHandler($this->drafts, $this->releases, $this->clock))(
            new CreateGameSystem($this->draftIds->generate()->toString(), $user, $key, $name, null, '' === $source ? null : $source, null),
        ));
    }

    #[When('/^(?P<user>\w+) creates the GameSystem "(?P<key>[^"]+)" named "(?P<name>[^"]+)" as a copy of "(?P<source>[^"]+)" described as "(?P<description>[^"]+)"$/')]
    public function createsACopyDescribed(string $user, string $key, string $name, string $source, string $description): void
    {
        $this->attempt(fn () => (new CreateGameSystemHandler($this->drafts, $this->releases, $this->clock))(
            new CreateGameSystem($this->draftIds->generate()->toString(), $user, $key, $name, $description, $source, null),
        ));
    }

    #[When('/^(?P<user>\w+) renames the GameSystem "(?P<key>[^"]+)" to "(?P<name>[^"]+)" with the theme "(?P<theme>[^"]+)"$/')]
    public function renames(string $user, string $key, string $name, string $theme): void
    {
        $this->attempt(fn () => (new EditGameSystemMetadataHandler($this->owned, $this->drafts, $this->clock))(
            new EditGameSystemMetadata($user, $key, $name, null, null, $theme),
        ));
    }

    #[When('the sections of :user\'s draft :key are emptied')]
    public function theSectionsAreEmptied(string $user, string $key): void
    {
        $draft = $this->owned->get($user, $key);
        $draft->restore(['schemaVersion' => 2, 'gameSystem' => ['key' => $key, 'name' => $draft->name()]], $this->clock->now());
    }

    #[Given('/^(?P<user>\w+)\'s draft "(?P<key>[^"]+)" was published$/')]
    public function theDraftWasPublished(string $user, string $key): void
    {
        new ReleasePublisher($this->releases, $this->clock)->publish($this->ids->generate(), ReleaseContent::fromArray($this->owned->get($user, $key)->document()), null, false);
    }

    #[When('/^(?P<user>\w+) discards the GameSystem "(?P<key>[^"]+)"$/')]
    public function discards(string $user, string $key): void
    {
        $this->attempt(fn () => (new DiscardGameSystemHandler($this->owned, $this->drafts, $this->releases))(new DiscardGameSystem($user, $key)));
    }

    #[Given('/^(?P<user>\w+) published the GameSystem "(?P<key>[^"]+)"(?: with the notes "(?P<notes>[^"]+)")?$/')]
    #[When('/^(?P<user>\w+) publishes the GameSystem "(?P<key>[^"]+)"(?: with the notes "(?P<notes>[^"]+)")?$/')]
    public function publishes(string $user, string $key, ?string $notes = null): void
    {
        $this->attempt(fn () => (new PublishGameSystemDraftHandler($this->owned, new ReleasePublisher($this->releases, $this->clock), $this->releases))(
            new PublishGameSystemDraft($user, $key, $this->ids->generate()->toString(), '' === $notes ? null : $notes),
        ));
    }

    #[When('/^(?P<user>\w+) restores release (?P<version>\d+) of "(?P<key>[^"]+)"$/')]
    public function restores(string $user, int $version, string $key): void
    {
        $this->attempt(fn () => (new RestoreGameSystemReleaseHandler($this->owned, $this->drafts, $this->releases, $this->clock))(new RestoreGameSystemRelease($user, $key, $version)));
    }

    #[Then('ann sees release :version of :key with the notes :notes')]
    public function seesTheReleaseNotes(int $version, string $key, string $notes): void
    {
        Assert::assertSame($notes, $this->releaseOf($key, $version)->summary->notes);
    }

    #[Then('ann sees release :version of :key named :name with :count flows')]
    public function seesTheReleaseNamed(int $version, string $key, string $name, int $count): void
    {
        $view = $this->releaseOf($key, $version);
        Assert::assertSame([$name, $count], [$view->name, $view->sectionCounts['flows']]);
    }

    #[Then('ann sees the history of :key with the versions :versions')]
    public function seesTheHistory(string $key, string $versions): void
    {
        $history = (new ListGameSystemReleasesHandler($this->owned, $this->releases))(new ListGameSystemReleases('ann', $key));
        Assert::assertSame($versions, implode(', ', array_map(static fn (GameSystemReleaseSummary $line): int => $line->version, $history)));
    }

    #[Then('ann sees the GameSystem :key at release :version without unpublished changes')]
    public function seesTheDraftAtRelease(string $key, int $version): void
    {
        $view = $this->draft('ann', $key);
        Assert::assertSame([$version, false], [$view->latestReleaseVersion, $view->hasUnpublishedChanges]);
    }

    #[Then('ann sees the GameSystem :key named :name at release :version without unpublished changes')]
    public function seesTheNamedDraftAtRelease(string $key, string $name, int $version): void
    {
        Assert::assertSame($name, $this->draft('ann', $key)->name);
        $this->seesTheDraftAtRelease($key, $version);
    }

    #[Then('the publish is refused at :path')]
    public function publishRefusedAt(string $path): void
    {
        Assert::assertInstanceOf(InvalidReleaseContent::class, $this->draftRefusal);
        Assert::assertStringStartsWith($path.': ', $this->draftRefusal->getMessage());
    }

    #[Then('the publish is refused because there are no changes')]
    public function publishRefusedUnchanged(): void
    {
        Assert::assertInstanceOf(GameSystemDraftUnchanged::class, $this->draftRefusal);
    }

    #[Then('the publish is refused because the release does not exist')]
    public function refusedUnknownRelease(): void
    {
        Assert::assertInstanceOf(GameSystemReleaseNotFound::class, $this->draftRefusal);
    }

    #[Then('the GameSystem is not found')]
    public function refusedNotFound(): void
    {
        Assert::assertInstanceOf(GameSystemNotFound::class, $this->draftRefusal);
    }

    #[Then('ann sees the GameSystem :key named :name with no release and unpublished changes')]
    public function seesTheDraft(string $key, string $name): void
    {
        $view = $this->draft('ann', $key);
        Assert::assertSame($name, $view->name);
        Assert::assertNull($view->latestReleaseVersion);
        Assert::assertTrue($view->hasUnpublishedChanges);
    }

    #[Then('ann sees the GameSystem :key named :name')]
    public function seesTheDraftNamed(string $key, string $name): void
    {
        Assert::assertSame($name, $this->draft('ann', $key)->name);
    }

    #[Then('ann sees the GameSystem :key described as :description')]
    public function seesTheDraftDescribed(string $key, string $description): void
    {
        Assert::assertSame($description, $this->draft('ann', $key)->description);
    }

    #[Then('ann sees the GameSystem :key named :name with the theme :theme')]
    public function seesTheDraftWithTheme(string $key, string $name, string $theme): void
    {
        $view = $this->draft('ann', $key);
        Assert::assertSame([$name, $theme], [$view->name, $view->theme]);
    }

    #[Then('/^(?P<user>\w+)\'s draft "(?P<key>[^"]+)" is valid$/')]
    public function theDraftIsValid(string $user, string $key): void
    {
        $validation = (new ValidateGameSystemDraftHandler($this->owned))(new ValidateGameSystemDraft($user, $key));
        Assert::assertTrue($validation->valid, (string) $validation->error);
    }

    #[Then('/^(?P<user>\w+)\'s draft "(?P<key>[^"]+)" is invalid at "(?P<path>[^"]+)"$/')]
    public function theDraftIsInvalidAt(string $user, string $key, string $path): void
    {
        $validation = (new ValidateGameSystemDraftHandler($this->owned))(new ValidateGameSystemDraft($user, $key));
        Assert::assertFalse($validation->valid);
        Assert::assertStringStartsWith($path.': ', (string) $validation->error);
    }

    #[Then('the GameSystem is refused because the key is taken')]
    public function refusedKeyTaken(): void
    {
        Assert::assertInstanceOf(GameSystemKeyTaken::class, $this->draftRefusal);
    }

    #[Then('the GameSystem is refused because it has releases')]
    public function refusedHasReleases(): void
    {
        Assert::assertInstanceOf(GameSystemHasReleases::class, $this->draftRefusal);
    }

    #[Then('the GameSystem is refused at :field')]
    public function refusedAt(string $field): void
    {
        Assert::assertInstanceOf(InvalidGameSystemMetadata::class, $this->draftRefusal);
        Assert::assertSame($field, $this->draftRefusal->field);
    }

    #[Then('/^(?P<user>\w+) has no GameSystems$/')]
    public function hasNoGameSystems(string $user): void
    {
        Assert::assertSame([], (new ListMyGameSystemsHandler($this->drafts, $this->releases))(new ListMyGameSystems($user)));
    }

    #[Then('bob cannot find the GameSystem :key')]
    public function cannotFind(string $key): void
    {
        $this->expectNotFound(fn (): \App\Studio\Application\GameSystemView => $this->draft('bob', $key));
    }

    #[Given('a published GameSystem release :key from the fixture :fixture')]
    public function aPublishedReleaseFromTheFixture(string $key, string $fixture): void
    {
        /** @var array<string, mixed> $release */
        $release = ReleaseArrays::with(ReleaseArrays::with(ReleaseArrays::fixture($fixture), 'gameSystem.key', $key), 'gameSystem.name', $key);
        $this->releaseFile = $release;
        $this->iPublishedTheRelease();
    }

    #[Given('/^(?P<user>\w+) saved the table "(?P<table>[^"]+)" named "(?P<name>[^"]+)" with the entries "(?P<entries>[^"]*)" in "(?P<key>[^"]+)"$/')]
    #[When('/^(?P<user>\w+) saves the table "(?P<table>[^"]+)" named "(?P<name>[^"]+)" with the entries "(?P<entries>[^"]*)" in "(?P<key>[^"]+)"(?P<stale> on a stale version)?$/')]
    public function savesATable(string $user, string $table, string $name, string $entries, string $key, string $stale = ''): void
    {
        $texts = '' === $entries ? [] : explode(', ', $entries);
        $definition = ['key' => $table, 'name' => $name, 'entries' => array_map(static fn (string $text): array => ['text' => $text], $texts)];
        $version = $this->oraclesOf('ann', $key)->version + ('' === $stale ? 0 : 1);
        $this->attempt(fn () => (new SaveOracleTableHandler($this->owned, $this->drafts, $this->clock))(new SaveOracleTable($user, $key, null, $definition, $version)));
    }

    #[When('/^(?P<user>\w+) renames the table "(?P<table>[^"]+)" of "(?P<key>[^"]+)" to "(?P<newKey>[^"]+)"$/')]
    public function renamesATable(string $user, string $table, string $key, string $newKey): void
    {
        $oracles = $this->oraclesOf($user, $key);
        $stored = array_first(array_filter($oracles->tables, static fn (OracleView $view): bool => $view->key === $table)) ?? null;
        Assert::assertInstanceOf(OracleView::class, $stored);
        $this->attempt(fn () => (new SaveOracleTableHandler($this->owned, $this->drafts, $this->clock))(new SaveOracleTable($user, $key, $table, [...$stored->definition, 'key' => $newKey], $oracles->version)));
    }

    #[When('/^(?P<user>\w+) deletes the table "(?P<table>[^"]+)" of "(?P<key>[^"]+)"$/')]
    public function deletesATable(string $user, string $table, string $key): void
    {
        $this->attempt(fn () => (new DeleteOracleTableHandler($this->owned, $this->drafts, $this->clock))(new DeleteOracleTable($user, $key, $table, $this->oraclesOf('ann', $key)->version)));
    }

    #[When('/^(?P<user>\w+) deletes the likelihood oracle "(?P<oracle>[^"]+)" of "(?P<key>[^"]+)"$/')]
    public function deletesALikelihoodOracle(string $user, string $oracle, string $key): void
    {
        $this->attempt(fn () => (new DeleteLikelihoodOracleHandler($this->owned, $this->drafts, $this->clock))(new DeleteLikelihoodOracle($user, $key, $oracle, $this->oraclesOf('ann', $key)->version)));
    }

    #[Then('ann sees the tables :tables and the likelihood oracles :likelihood in :key')]
    public function seesTheOracles(string $tables, string $likelihood, string $key): void
    {
        $oracles = $this->oraclesOf('ann', $key);
        Assert::assertSame([$tables, $likelihood], [$this->keysOf($oracles->tables), $this->keysOf($oracles->likelihood)]);
    }

    #[Then('the table :table of :key is used at :path')]
    public function theTableIsUsedAt(string $table, string $key, string $path): void
    {
        foreach ($this->oraclesOf('ann', $key)->tables as $view) {
            if ($view->key === $table) {
                Assert::assertContains($path, $view->usedBy);

                return;
            }
        }

        Assert::fail('No such table.');
    }

    #[Then('the oracle change is refused because it is in use at :path')]
    public function refusedInUse(string $path): void
    {
        Assert::assertInstanceOf(OracleInUse::class, $this->draftRefusal);
        Assert::assertContains($path, $this->draftRefusal->paths());
    }

    #[Then('the oracle change is refused at :path')]
    public function oracleChangeRefusedAt(string $path): void
    {
        Assert::assertInstanceOf(InvalidReleaseContent::class, $this->draftRefusal);
        Assert::assertStringStartsWith($path.': ', $this->draftRefusal->getMessage());
    }

    #[Then('the oracle change is refused because the key is taken')]
    public function refusedOracleKeyTaken(): void
    {
        Assert::assertInstanceOf(OracleKeyTaken::class, $this->draftRefusal);
    }

    #[Then('the oracle change is refused because the draft changed meanwhile')]
    public function refusedStale(): void
    {
        Assert::assertInstanceOf(GameSystemModifiedConcurrently::class, $this->draftRefusal);
    }

    #[Given('the dice will roll :numbers')]
    public function theDiceWillRoll(string $numbers): void
    {
        $this->dice = new ScriptedRandomNumberGenerator(...array_map(intval(...), explode(', ', $numbers)));
    }

    #[When('/^(?P<user>\w+) test-rolls the table "(?P<table>[^"]+)" of "(?P<key>[^"]+)"$/')]
    public function testRollsATable(string $user, string $table, string $key): void
    {
        $this->documentBeforeTest = $this->owned->get($user, $key)->document();
        $this->tableRoll = null;
        $this->attempt(function () use ($user, $table, $key): void {
            $this->tableRoll = (new TestRollOracleTableHandler($this->owned, new RandomnessQueryBus($this->dice)))(new TestRollOracleTable($user, $key, $table));
        });
    }

    #[When('/^(?P<user>\w+) test-asks the likelihood oracle "(?P<oracle>[^"]+)" of "(?P<key>[^"]+)" with the level "(?P<level>[^"]+)" and the chaos factor (?P<chaos>\d+)$/')]
    public function testAsksALikelihoodOracle(string $user, string $oracle, string $key, string $level, int $chaos): void
    {
        $this->documentBeforeTest = $this->owned->get($user, $key)->document();
        $this->likelihoodTest = null;
        $this->attempt(function () use ($user, $oracle, $key, $level, $chaos): void {
            $this->likelihoodTest = (new TestAskLikelihoodOracleHandler($this->owned, new RandomnessQueryBus($this->dice)))(new TestAskLikelihoodOracle($user, $key, $oracle, $level, $chaos));
        });
    }

    #[Then('the test roll went through the tables :tables and ended on :text')]
    public function theTestRollWentThrough(string $tables, string $text): void
    {
        Assert::assertInstanceOf(OracleTableResultView::class, $this->tableRoll);
        $steps = $this->tableRoll->steps;
        Assert::assertSame([$tables, $text], [implode(', ', array_map(static fn (\App\Randomness\Application\OracleTableStepView $step): string => $step->tableKey, $steps)), array_last($steps)->text]);
    }

    #[Then('the answer is :answer and the random event :table gives :text')]
    public function theAnswerHasARandomEvent(string $answer, string $table, string $text): void
    {
        Assert::assertInstanceOf(LikelihoodTestView::class, $this->likelihoodTest);
        Assert::assertInstanceOf(OracleTableResultView::class, $this->likelihoodTest->randomEvent);
        Assert::assertSame([$answer, $table, $text], [$this->likelihoodTest->answer->answer, $this->likelihoodTest->randomEvent->table, $this->likelihoodTest->randomEvent->steps[0]->text]);
    }

    #[Then('the answer is :answer and no random event is rolled')]
    public function theAnswerHasNoRandomEvent(string $answer): void
    {
        Assert::assertInstanceOf(LikelihoodTestView::class, $this->likelihoodTest);
        Assert::assertSame($answer, $this->likelihoodTest->answer->answer);
        Assert::assertNull($this->likelihoodTest->randomEvent);
    }

    #[Then('/^(?P<user>\w+)\'s draft "(?P<key>[^"]+)" is unchanged$/')]
    public function theDraftIsUnchanged(string $user, string $key): void
    {
        Assert::assertSame($this->documentBeforeTest, $this->owned->get($user, $key)->document());
    }

    #[Then('the oracle change is refused because it does not exist')]
    public function refusedOracleNotFound(): void
    {
        Assert::assertInstanceOf(OracleNotFound::class, $this->draftRefusal);
    }

    /**
     * @param list<OracleView> $views
     */
    private function keysOf(array $views): string
    {
        return implode(', ', array_map(static fn (OracleView $view): string => $view->key, $views));
    }

    private function oraclesOf(string $user, string $key): GameSystemOraclesView
    {
        return (new GetGameSystemOraclesHandler($this->owned))(new GetGameSystemOracles($user, $key));
    }

    private function attempt(\Closure $action): void
    {
        $this->draftRefusal = null;

        try {
            $action();
        } catch (GameSystemKeyTaken|GameSystemHasReleases|InvalidGameSystemMetadata|InvalidReleaseContent|GameSystemDraftUnchanged|GameSystemReleaseNotFound|GameSystemNotFound|GameSystemModifiedConcurrently|OracleInUse|OracleKeyTaken|OracleNotFound $refusal) {
            $this->draftRefusal = $refusal;
        }
    }

    private function expectNotFound(\Closure $action): void
    {
        try {
            $action();
            Assert::fail('The GameSystem was found.');
        } catch (GameSystemNotFound) {
            // Another owner's GameSystem is "not found".
        }
    }

    private function releaseOf(string $key, int $version): GameSystemReleaseView
    {
        return (new GetGameSystemReleaseHandler($this->owned, $this->releases))(new GetGameSystemRelease('ann', $key, $version));
    }

    private function draft(string $user, string $key): \App\Studio\Application\GameSystemView
    {
        return (new GetGameSystemHandler($this->owned, $this->releases))(new GetGameSystem($user, $key));
    }
}
