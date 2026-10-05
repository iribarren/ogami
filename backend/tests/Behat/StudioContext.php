<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Studio\Application\GetPublishedRelease;
use App\Studio\Application\GetPublishedReleaseHandler;
use App\Studio\Application\PublishedReleaseView;
use App\Studio\Application\PublishGameSystemRelease;
use App\Studio\Application\PublishGameSystemReleaseHandler;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseId;
use App\Tests\Support\Studio\FixedClock;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use App\Tests\Support\Studio\SequentialReleaseIdGenerator;
use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use PHPUnit\Framework\Assert;

/**
 * Publishing GameSystem releases in Studio, on in-memory fakes (no kernel).
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

    public function __construct()
    {
        $this->releases = new InMemoryGameSystemReleaseRepository();
        $this->ids = new SequentialReleaseIdGenerator();
        $this->publish = new PublishGameSystemReleaseHandler($this->releases, new FixedClock());
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
}
