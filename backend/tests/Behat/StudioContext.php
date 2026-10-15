<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Studio\Application\CreateGameSystem;
use App\Studio\Application\CreateGameSystemHandler;
use App\Studio\Application\DiscardGameSystem;
use App\Studio\Application\DiscardGameSystemHandler;
use App\Studio\Application\EditGameSystemMetadata;
use App\Studio\Application\EditGameSystemMetadataHandler;
use App\Studio\Application\GetGameSystem;
use App\Studio\Application\GetGameSystemHandler;
use App\Studio\Application\GetPublishedRelease;
use App\Studio\Application\GetPublishedReleaseHandler;
use App\Studio\Application\ListMyGameSystems;
use App\Studio\Application\ListMyGameSystemsHandler;
use App\Studio\Application\OwnedGameSystems;
use App\Studio\Application\PublishedReleaseView;
use App\Studio\Application\PublishGameSystemRelease;
use App\Studio\Application\PublishGameSystemReleaseHandler;
use App\Studio\Application\ValidateGameSystemDraft;
use App\Studio\Application\ValidateGameSystemDraftHandler;
use App\Studio\Domain\GameSystem\GameSystemHasReleases;
use App\Studio\Domain\GameSystem\GameSystemKeyTaken;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\InvalidGameSystemMetadata;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseId;
use App\Tests\Support\Studio\FixedClock;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use App\Tests\Support\Studio\InMemoryGameSystemRepository;
use App\Tests\Support\Studio\SequentialGameSystemIdGenerator;
use App\Tests\Support\Studio\SequentialReleaseIdGenerator;
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

    public function __construct()
    {
        $this->releases = new InMemoryGameSystemReleaseRepository();
        $this->ids = new SequentialReleaseIdGenerator();
        $this->clock = new FixedClock();
        $this->publish = new PublishGameSystemReleaseHandler($this->releases, $this->clock);
        $this->drafts = new InMemoryGameSystemRepository();
        $this->draftIds = new SequentialGameSystemIdGenerator();
        $this->owned = new OwnedGameSystems($this->drafts);
        $this->getRelease = new GetPublishedReleaseHandler($this->releases);
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
        ($this->publish)(new PublishGameSystemRelease($this->ids->generate()->toString(), $this->owned->get($user, $key)->document(), false));
    }

    #[When('/^(?P<user>\w+) discards the GameSystem "(?P<key>[^"]+)"$/')]
    public function discards(string $user, string $key): void
    {
        $this->attempt(fn () => (new DiscardGameSystemHandler($this->owned, $this->drafts, $this->releases))(new DiscardGameSystem($user, $key)));
    }

    #[Then('ann sees the GameSystem :key named :name with no release and unpublished changes')]
    public function seesTheDraft(string $key, string $name): void
    {
        $view = $this->draft('ann', $key);
        Assert::assertSame($name, $view->name);
        Assert::assertNull($view->latestReleaseVersion);
        Assert::assertTrue($view->hasUnpublishedChanges);
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

    private function attempt(\Closure $action): void
    {
        $this->draftRefusal = null;

        try {
            $action();
        } catch (GameSystemKeyTaken|GameSystemHasReleases|InvalidGameSystemMetadata $refusal) {
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

    private function draft(string $user, string $key): \App\Studio\Application\GameSystemView
    {
        return (new GetGameSystemHandler($this->owned, $this->releases))(new GetGameSystem($user, $key));
    }
}
