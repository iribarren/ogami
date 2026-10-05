<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\PublishGameSystemRelease;
use App\Studio\Application\PublishGameSystemReleaseHandler;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;
use App\Tests\Support\Studio\FixedClock;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublishGameSystemRelease::class)]
#[CoversClass(PublishGameSystemReleaseHandler::class)]
final class PublishGameSystemReleaseHandlerTest extends TestCase
{
    private InMemoryGameSystemReleaseRepository $releases;
    private FixedClock $clock;
    private PublishGameSystemReleaseHandler $handler;

    protected function setUp(): void
    {
        $this->releases = new InMemoryGameSystemReleaseRepository();
        $this->clock = new FixedClock('2026-10-05T10:00:00+00:00');
        $this->handler = new PublishGameSystemReleaseHandler($this->releases, $this->clock);
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
