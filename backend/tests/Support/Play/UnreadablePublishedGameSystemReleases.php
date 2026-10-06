<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Application\PublishedGameSystemReleases;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Domain\GameSystem\UnsupportedReleaseSchemaVersion;

/**
 * Port double whose releases cannot be read: every get() throws the given Play error, as the
 * Studio-backed adapter does for a missing, unsupported or broken release.
 */
final readonly class UnreadablePublishedGameSystemReleases implements PublishedGameSystemReleases
{
    public function __construct(
        private GameSystemReleaseNotFound|InvalidGameSystemRelease|UnsupportedReleaseSchemaVersion $error,
    ) {
    }

    /**
     * Every Play error a pinned release can fail with.
     *
     * @return iterable<string, array{GameSystemReleaseNotFound|InvalidGameSystemRelease|UnsupportedReleaseSchemaVersion}>
     */
    public static function errors(): iterable
    {
        yield 'not found' => [GameSystemReleaseNotFound::for('free-journal', 1)];
        yield 'unsupported schema version' => [UnsupportedReleaseSchemaVersion::of('free-journal', 1, 99, [1])];
        yield 'broken content' => [InvalidGameSystemRelease::of('free-journal', 1, 'oracles', 'broken.')];
    }

    public function get(string $gameSystemKey, ?int $version = null): GameSystemSnapshot
    {
        throw $this->error;
    }

    public function latest(): array
    {
        throw $this->error;
    }
}
