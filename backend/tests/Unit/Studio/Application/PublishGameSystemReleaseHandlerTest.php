<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\PublishGameSystemRelease;
use App\Studio\Application\PublishGameSystemReleaseHandler;
use App\Studio\Application\ReleasePublisher;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\GameSystemManagedInStudio;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseAlreadyExists;
use App\Studio\Domain\Release\GameSystemReleaseRepository;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;
use App\Tests\Support\Studio\FixedClock;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use App\Tests\Support\Studio\InMemoryGameSystemRepository;
use App\Tests\Support\Studio\InterleavingGameSystemReleaseRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublishGameSystemRelease::class)]
#[CoversClass(PublishGameSystemReleaseHandler::class)]
#[CoversClass(ReleasePublisher::class)]
final class PublishGameSystemReleaseHandlerTest extends TestCase
{
    private InMemoryGameSystemReleaseRepository $releases;
    private FixedClock $clock;
    private InMemoryGameSystemRepository $gameSystems;
    private PublishGameSystemReleaseHandler $handler;

    protected function setUp(): void
    {
        $this->releases = new InMemoryGameSystemReleaseRepository();
        $this->clock = new FixedClock('2026-10-05T10:00:00+00:00');
        $this->gameSystems = new InMemoryGameSystemRepository();
        $this->handler = new PublishGameSystemReleaseHandler(new ReleasePublisher($this->releases, $this->clock), $this->gameSystems);
    }

    #[Test]
    public function theFirstReleaseOfAGameSystemIsVersionOne(): void
    {
        ($this->handler)(new PublishGameSystemRelease('r-1', $this->content(), false));

        $release = $this->releases->ofId(ReleaseId::fromString('r-1'));
        self::assertInstanceOf(GameSystemRelease::class, $release);
        self::assertSame('free-journal', $release->gameSystemKey());
        self::assertSame(1, $release->version());
        self::assertSame(1, $release->schemaVersion());
        self::assertSame(ReleaseContent::fromArray($this->content())->hash(), $release->contentHash());
        self::assertEquals(new \DateTimeImmutable('2026-10-05T10:00:00+00:00'), $release->publishedAt());
    }

    #[Test]
    public function eachPublishAddsTheNextVersionAndKeepsThePreviousOnes(): void
    {
        ($this->handler)(new PublishGameSystemRelease('r-1', $this->content(), false));
        $this->clock->moveTo('2026-10-06T08:30:00+00:00');

        ($this->handler)(new PublishGameSystemRelease('r-2', $this->content(), false));

        self::assertSame(2, $this->releases->count());
        $latest = $this->releases->latestFor('free-journal');
        self::assertNotNull($latest);
        self::assertSame('r-2', $latest->id()->toString());
        self::assertSame(2, $latest->version());
        self::assertEquals(new \DateTimeImmutable('2026-10-06T08:30:00+00:00'), $latest->publishedAt());
        self::assertSame('r-1', $this->releases->get('free-journal', 1)?->id()->toString());
    }

    #[Test]
    public function versionsAreCountedPerGameSystem(): void
    {
        ($this->handler)(new PublishGameSystemRelease('r-1', $this->content(), false));

        ($this->handler)(new PublishGameSystemRelease('r-2', $this->content(key: 'mythic-style'), false));

        self::assertSame(1, $this->releases->latestFor('mythic-style')?->version());
    }

    #[Test]
    public function onlyIfChangedPublishesNothingWhenTheLatestReleaseHasTheSameContent(): void
    {
        ($this->handler)(new PublishGameSystemRelease('r-1', $this->content(), true));

        // Same content, other key order: the canonical hash is the same.
        ($this->handler)(new PublishGameSystemRelease('r-2', array_reverse($this->content(), true), true));

        self::assertSame(1, $this->releases->count());
        self::assertNull($this->releases->ofId(ReleaseId::fromString('r-2')));
    }

    #[Test]
    public function onlyIfChangedPublishesTheNextVersionWhenTheContentChanged(): void
    {
        ($this->handler)(new PublishGameSystemRelease('r-1', $this->content(), true));

        ($this->handler)(new PublishGameSystemRelease('r-2', $this->content(name: 'Free journal, revised'), true));

        self::assertSame(2, $this->releases->latestFor('free-journal')?->version());
    }

    #[Test]
    public function withoutOnlyIfChangedTheSameContentIsPublishedAgain(): void
    {
        ($this->handler)(new PublishGameSystemRelease('r-1', $this->content(), false));

        ($this->handler)(new PublishGameSystemRelease('r-2', $this->content(), false));

        self::assertSame(2, $this->releases->latestFor('free-journal')?->version());
    }

    #[Test]
    public function invalidContentIsRejectedAndNothingIsStored(): void
    {
        $content = $this->content();
        $content['checks'] = [['key' => 'strength']];

        try {
            ($this->handler)(new PublishGameSystemRelease('r-1', $content, false));
            self::fail('Invalid content was published.');
        } catch (InvalidReleaseContent $exception) {
            self::assertStringStartsWith('checks: ', $exception->getMessage());
        }

        self::assertSame(0, $this->releases->count());
    }

    #[Test]
    public function aConcurrentPublishOfTheSameVersionIsReported(): void
    {
        $racing = new InterleavingGameSystemReleaseRepository(
            $this->releases,
            beforeAdd: static function (GameSystemReleaseRepository $releases, GameSystemRelease $release): void {
                $releases->add(GameSystemRelease::publish(ReleaseId::fromString('other'), $release->content(), $release->version(), $release->publishedAt()));
            },
        );
        $handler = new PublishGameSystemReleaseHandler(new ReleasePublisher($racing, $this->clock), $this->gameSystems);

        try {
            $handler(new PublishGameSystemRelease('r-1', $this->content(), false));
            self::fail('Two releases share a version.');
        } catch (GameSystemReleaseAlreadyExists $exception) {
            self::assertSame('free-journal', $exception->gameSystemKey);
            self::assertSame(1, $exception->version);
        }

        self::assertSame(1, $this->releases->count());
        self::assertNull($this->releases->ofId(ReleaseId::fromString('r-1')));
    }

    #[Test]
    public function aKeyOfAStudioGameSystemIsRefusedAndNothingIsStored(): void
    {
        $this->gameSystems->add(GameSystem::createBlank(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a51'), 'free-journal', 'Mine', null, 'ann', $this->clock->now()));

        foreach ([false, true] as $onlyIfChanged) {
            try {
                ($this->handler)(new PublishGameSystemRelease('r-1', $this->content(), $onlyIfChanged));
                self::fail('A Studio GameSystem line was published to.');
            } catch (GameSystemManagedInStudio $refusal) {
                self::assertStringContainsString('free-journal', $refusal->getMessage());
            }
        }

        self::assertSame(0, $this->releases->count());
    }

    #[Test]
    public function aStudioKeyWhoseLatestReleaseHasTheSameContentIsSkippedWhenOnlyIfChanged(): void
    {
        ($this->handler)(new PublishGameSystemRelease('r-1', $this->content(), false));
        $this->studioOwns('free-journal');

        // Same content, other key order: the canonical hash is the same.
        ($this->handler)(new PublishGameSystemRelease('r-2', array_reverse($this->content(), true), true));

        self::assertSame(1, $this->releases->count());
        self::assertNull($this->releases->ofId(ReleaseId::fromString('r-2')));
    }

    #[Test]
    public function aStudioKeyWhoseLatestReleaseHasOtherContentIsRefusedEvenWhenOnlyIfChanged(): void
    {
        ($this->handler)(new PublishGameSystemRelease('r-1', $this->content(), false));
        $this->studioOwns('free-journal');

        $this->assertManagedInStudio($this->content(name: 'Free journal, revised'), true);
    }

    #[Test]
    public function aStudioKeyWhoseLatestReleaseHasTheSameContentIsRefusedWithoutOnlyIfChanged(): void
    {
        ($this->handler)(new PublishGameSystemRelease('r-1', $this->content(), false));
        $this->studioOwns('free-journal');

        $this->assertManagedInStudio($this->content(), false);
    }

    #[Test]
    public function aStudioKeyIsComparedWithItsLatestReleaseOnly(): void
    {
        ($this->handler)(new PublishGameSystemRelease('r-1', $this->content(), false));
        ($this->handler)(new PublishGameSystemRelease('r-2', $this->content(name: 'Free journal, revised'), false));
        $this->studioOwns('free-journal');

        $this->assertManagedInStudio($this->content(), true);
    }

    #[Test]
    public function aStudioKeyWithoutAReleaseIsRefusedEvenWhenOnlyIfChanged(): void
    {
        $this->studioOwns('free-journal');

        $this->assertManagedInStudio($this->content(), true);
    }

    private function studioOwns(string $key): void
    {
        $this->gameSystems->add(GameSystem::createBlank(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a52'), $key, 'Mine', null, 'ann', $this->clock->now()));
    }

    /**
     * @param array<string, mixed> $content
     */
    private function assertManagedInStudio(array $content, bool $onlyIfChanged): void
    {
        $before = $this->releases->count();

        try {
            ($this->handler)(new PublishGameSystemRelease('r-9', $content, $onlyIfChanged));
            self::fail('A Studio GameSystem line was published to.');
        } catch (GameSystemManagedInStudio $refusal) {
            self::assertStringContainsString('free-journal', $refusal->getMessage());
        }

        self::assertSame($before, $this->releases->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function content(string $key = 'free-journal', string $name = 'Free journal'): array
    {
        return [
            'schemaVersion' => 1,
            'gameSystem' => ['key' => $key, 'name' => $name],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'flow' => ['steps' => [['key' => 'set-scene', 'title' => 'Set the scene']]],
            'sheet' => [],
            'checks' => [],
        ];
    }
}
