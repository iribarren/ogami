<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Studio\Application\PublishedReleaseView;

/**
 * Builds the PublishedReleaseView Studio hands Play, from the release fixture files.
 */
final class ReleaseViews
{
    public const string CONTRACT_DOC_EXAMPLE = __DIR__.'/../../Fixtures/Studio/releases/valid/contract-doc-example.json';

    /**
     * @return array<string, mixed> the decoded release file, objects as string-keyed arrays
     */
    public static function contractDocExampleContent(): array
    {
        /** @var array<string, mixed> $content */
        $content = json_decode((string) file_get_contents(self::CONTRACT_DOC_EXAMPLE), true, flags: \JSON_THROW_ON_ERROR);

        return $content;
    }

    /**
     * @param string $name a fixture of tests/Fixtures/Studio/releases, e.g. "valid/v2-scene-types"
     *
     * @return array<string, mixed> the decoded release file, objects as string-keyed arrays
     */
    public static function fixtureContent(string $name): array
    {
        /** @var array<string, mixed> $content */
        $content = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/Studio/releases/'.$name.'.json'), true, flags: \JSON_THROW_ON_ERROR);

        return $content;
    }

    /**
     * @param array<string, mixed> $content the release file; "sheet" becomes a \stdClass as Studio hands it
     */
    public static function of(array $content, int $version = 1, ?int $schemaVersion = null): PublishedReleaseView
    {
        $content['sheet'] = new \stdClass();
        $gameSystem = $content['gameSystem'] ?? null;
        $key = \is_array($gameSystem) && \is_string($gameSystem['key'] ?? null) ? $gameSystem['key'] : 'unknown';

        return new PublishedReleaseView(
            \sprintf('release-%d', $version),
            $key,
            $version,
            $schemaVersion ?? (\is_int($content['schemaVersion'] ?? null) ? $content['schemaVersion'] : 1),
            new \DateTimeImmutable('2026-10-05T10:00:00+00:00'),
            $content,
        );
    }
}
