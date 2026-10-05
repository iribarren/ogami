<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\GetPublishedRelease;
use App\Studio\Application\GetPublishedReleaseHandler;
use App\Studio\Application\PublishedReleaseNotFound;
use App\Studio\Application\PublishedReleaseView;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetPublishedRelease::class)]
#[CoversClass(GetPublishedReleaseHandler::class)]
#[CoversClass(PublishedReleaseView::class)]
#[CoversClass(PublishedReleaseNotFound::class)]
final class GetPublishedReleaseHandlerTest extends TestCase
{
    private InMemoryGameSystemReleaseRepository $releases;
    private GetPublishedReleaseHandler $handler;

    protected function setUp(): void
    {
        $this->releases = new InMemoryGameSystemReleaseRepository();
        $this->handler = new GetPublishedReleaseHandler($this->releases);
        $this->publish('r-1', 'Free journal', 1, '2026-10-05T10:00:00+00:00');
        $this->publish('r-2', 'Free journal, revised', 2, '2026-10-06T10:00:00+00:00');
    }

    #[Test]
    public function itGivesTheLatestReleaseWhenNoVersionIsAsked(): void
    {
        $view = ($this->handler)(new GetPublishedRelease('free-journal'));

        self::assertSame('r-2', $view->releaseId);
        self::assertSame('free-journal', $view->gameSystemKey);
        self::assertSame(2, $view->version);
        self::assertSame(1, $view->schemaVersion);
        self::assertEquals(new \DateTimeImmutable('2026-10-06T10:00:00+00:00'), $view->publishedAt);
        self::assertIsArray($view->content['gameSystem']);
        self::assertSame('Free journal, revised', $view->content['gameSystem']['name']);
    }

    #[Test]
    public function itGivesTheAskedVersion(): void
    {
        $view = ($this->handler)(new GetPublishedRelease('free-journal', 1));

        self::assertSame('r-1', $view->releaseId);
        self::assertSame(1, $view->version);
    }

    #[Test]
    public function theContentIsTheCanonicalReleaseDocument(): void
    {
        $view = ($this->handler)(new GetPublishedRelease('free-journal', 1));

        $expected = $this->content('Free journal');
        self::assertSame(
            json_encode(ReleaseContent::fromArray($expected)->toArray(), \JSON_THROW_ON_ERROR),
            json_encode($view->content, \JSON_THROW_ON_ERROR),
        );
        self::assertEquals(new \stdClass(), $view->content['sheet']);
        self::assertSame(hash('sha256', json_encode($view->content, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)), ReleaseContent::fromArray($expected)->hash());
    }

    #[Test]
    public function anUnknownGameSystemIsNotFound(): void
    {
        $this->expectException(PublishedReleaseNotFound::class);
        $this->expectExceptionMessageIsOrContains('No published release of GameSystem "unknown".');

        ($this->handler)(new GetPublishedRelease('unknown'));
    }

    #[Test]
    public function anUnknownVersionIsNotFound(): void
    {
        $this->expectException(PublishedReleaseNotFound::class);
        $this->expectExceptionMessageIsOrContains('No published release v3 of GameSystem "free-journal".');

        ($this->handler)(new GetPublishedRelease('free-journal', 3));
    }

    private function publish(string $id, string $name, int $version, string $at): void
    {
        $this->releases->add(GameSystemRelease::publish(
            ReleaseId::fromString($id),
            ReleaseContent::fromArray($this->content($name)),
            $version,
            new \DateTimeImmutable($at),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function content(string $name): array
    {
        return [
            'schemaVersion' => 1,
            'gameSystem' => ['key' => 'free-journal', 'name' => $name, 'description' => 'Any setting.'],
            'oracles' => [
                'tables' => [['key' => 'action', 'name' => 'Action', 'entries' => [['text' => 'Seek'], ['text' => 'Guard']]]],
                'likelihood' => [],
            ],
            'flow' => ['steps' => []],
            'sheet' => [],
            'checks' => [],
        ];
    }
}
