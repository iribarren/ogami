<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\GameSystem;

use App\Play\Application\PublishedGameSystemReleases;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Shared\Application\Bus\QueryBus;
use App\Studio\Application\GetPublishedRelease;
use App\Studio\Application\PublishedReleaseNotFound;

/**
 * Reads published GameSystem releases from Studio's Published Language (GetPublishedRelease on the
 * query bus) and translates them into Play snapshots.
 */
final readonly class StudioPublishedGameSystemReleases implements PublishedGameSystemReleases
{
    public function __construct(
        private QueryBus $queries,
        private GameSystemReleaseTranslator $translator,
    ) {
    }

    public function get(string $gameSystemKey, ?int $version = null): GameSystemSnapshot
    {
        try {
            $view = $this->queries->ask(new GetPublishedRelease($gameSystemKey, $version));
        } catch (PublishedReleaseNotFound $exception) {
            throw GameSystemReleaseNotFound::for($gameSystemKey, $version, $exception);
        }

        return $this->translator->translate($view);
    }
}
