<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\GetPublishedReleaseById;
use App\Studio\Application\GetPublishedReleaseByIdHandler;
use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetPublishedReleaseById::class)]
#[CoversClass(GetPublishedReleaseByIdHandler::class)]
final class GetPublishedReleaseByIdHandlerTest extends TestCase
{
    private InMemoryGameSystemReleaseRepository $releases;
    private GetPublishedReleaseByIdHandler $handler;

    protected function setUp(): void
    {
        $this->releases = new InMemoryGameSystemReleaseRepository();
        $this->handler = new GetPublishedReleaseByIdHandler($this->releases);
        $content = ReleaseContent::fromArray([
            'schemaVersion' => 1,
            'gameSystem' => ['key' => 'free-journal', 'name' => 'Free journal'],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'flow' => ['steps' => []],
            'sheet' => [],
            'checks' => [],
        ]);
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString('r-1'), $content, 1, new \DateTimeImmutable('2026-10-05T10:00:00+00:00')));
        $this->releases->add(GameSystemRelease::publish(ReleaseId::fromString('r-2'), $content, 2, new \DateTimeImmutable('2026-10-06T10:00:00+00:00')));
    }

    #[Test]
    public function itGivesTheReleaseWithThatIdEvenWhenANewerOneExists(): void
    {
        $view = ($this->handler)(new GetPublishedReleaseById('r-1'));

        self::assertNotNull($view);
        self::assertSame('r-1', $view->releaseId);
        self::assertSame('free-journal', $view->gameSystemKey);
        self::assertSame(1, $view->version);
        self::assertEquals(new \DateTimeImmutable('2026-10-05T10:00:00+00:00'), $view->publishedAt);
        self::assertEquals(new \stdClass(), $view->content['sheet']);
    }

    #[Test]
    public function itGivesNothingForAnUnknownId(): void
    {
        self::assertNull(($this->handler)(new GetPublishedReleaseById('r-9')));
    }
}
