<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\GameSystemRelease;
use App\Studio\Domain\Release\GameSystemReleaseAlreadyExists;
use App\Studio\Domain\Release\InvalidGameSystemRelease;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\ReleaseId;
use App\Tests\Support\Studio\InMemoryGameSystemReleaseRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GameSystemRelease::class)]
#[CoversClass(InvalidGameSystemRelease::class)]
#[CoversClass(GameSystemReleaseAlreadyExists::class)]
final class GameSystemReleaseTest extends TestCase
{
    private function content(string $key = 'free-journal', string $name = 'Free journal'): ReleaseContent
    {
        return ReleaseContent::fromArray([
            'schemaVersion' => 1,
            'gameSystem' => ['key' => $key, 'name' => $name],
            'oracles' => ['tables' => [], 'likelihood' => []],
            'flow' => ['steps' => []],
            'sheet' => [],
            'checks' => [],
        ]);
    }

    #[Test]
    public function itPublishesAnImmutableVersionOfTheContent(): void
    {
        $content = $this->content();
        $publishedAt = new \DateTimeImmutable('2026-10-05T10:00:00+00:00');

        $release = GameSystemRelease::publish(ReleaseId::fromString('r-1'), $content, 3, $publishedAt);

        self::assertTrue($release->id()->equals(ReleaseId::fromString('r-1')));
        self::assertSame('free-journal', $release->gameSystemKey());
        self::assertSame(3, $release->version());
        self::assertSame(1, $release->schemaVersion());
        self::assertSame($content->hash(), $release->contentHash());
        self::assertSame($content->hash(), $release->content()->hash());
        self::assertEquals($publishedAt, $release->publishedAt());
    }

    #[Test]
    public function itRejectsAVersionBelowOne(): void
    {
        $this->expectException(InvalidGameSystemRelease::class);

        GameSystemRelease::publish(ReleaseId::fromString('r-1'), $this->content(), 0, new \DateTimeImmutable());
    }

    #[Test]
    public function theInMemoryRepositoryFindsReleasesByKeyVersionAndId(): void
    {
        $repository = new InMemoryGameSystemReleaseRepository();
        $now = new \DateTimeImmutable();
        $repository->add(GameSystemRelease::publish(ReleaseId::fromString('r-1'), $this->content(), 1, $now));
        $repository->add(GameSystemRelease::publish(ReleaseId::fromString('r-2'), $this->content(name: 'Free journal 2'), 2, $now));
        $repository->add(GameSystemRelease::publish(ReleaseId::fromString('r-3'), $this->content('mythic-style', 'Mythic style'), 1, $now));

        self::assertSame(2, $repository->latestFor('free-journal')?->version());
        self::assertSame('r-1', $repository->get('free-journal', 1)?->id()->toString());
        self::assertSame('mythic-style', $repository->ofId(ReleaseId::fromString('r-3'))?->gameSystemKey());
        self::assertNull($repository->latestFor('unknown'));
        self::assertNull($repository->get('free-journal', 3));
        self::assertNull($repository->ofId(ReleaseId::fromString('r-9')));
    }

    #[Test]
    public function theInMemoryRepositoryRejectsADuplicateId(): void
    {
        $repository = new InMemoryGameSystemReleaseRepository();
        $repository->add(GameSystemRelease::publish(ReleaseId::fromString('r-1'), self::content(), 1, new \DateTimeImmutable()));

        $this->expectException(\LogicException::class);

        $repository->add(GameSystemRelease::publish(ReleaseId::fromString('r-1'), self::content('mythic-style', 'Mythic style'), 1, new \DateTimeImmutable()));
    }

    #[Test]
    public function theInMemoryRepositoryRejectsADuplicateKeyAndVersion(): void
    {
        $repository = new InMemoryGameSystemReleaseRepository();
        $repository->add(GameSystemRelease::publish(ReleaseId::fromString('r-1'), self::content(), 1, new \DateTimeImmutable()));

        $this->expectException(GameSystemReleaseAlreadyExists::class);
        $this->expectExceptionMessageIsOrContains('Release free-journal v1 already exists.');

        $repository->add(GameSystemRelease::publish(ReleaseId::fromString('r-2'), self::content(name: 'Free journal 2'), 1, new \DateTimeImmutable()));
    }

    #[Test]
    public function releaseNotesAreOptionalAndKeptAsGiven(): void
    {
        $plain = GameSystemRelease::publish(ReleaseId::fromString('r-1'), $this->content(), 1, new \DateTimeImmutable());
        $noted = GameSystemRelease::publish(ReleaseId::fromString('r-2'), $this->content(), 2, new \DateTimeImmutable(), "Fixed the oracles.\nAdded a check.");
        $longest = GameSystemRelease::publish(ReleaseId::fromString('r-3'), $this->content(), 3, new \DateTimeImmutable(), str_repeat('é', 2000));

        self::assertNull($plain->notes());
        self::assertSame("Fixed the oracles.\nAdded a check.", $noted->notes());
        self::assertSame(2000, mb_strlen((string) $longest->notes()));
        self::assertSame($plain->contentHash(), $noted->contentHash(), 'Notes are not part of the content hash.');
    }

    #[Test]
    public function blankOrTooLongNotesAreRefused(): void
    {
        foreach (['' => 'blank', " \n\t" => 'blank', str_repeat('é', 2001) => 'at most 2000 characters'] as $notes => $reason) {
            try {
                GameSystemRelease::publish(ReleaseId::fromString('r-1'), $this->content(), 1, new \DateTimeImmutable(), $notes);
                self::fail('The notes were accepted.');
            } catch (InvalidGameSystemRelease $refusal) {
                self::assertStringContainsString($reason, $refusal->getMessage());
            }
        }
    }
}
