<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release;

use App\Studio\Domain\Release\Version2\ReleaseVersion2;

/**
 * The one validator of a release's "oracles" section, for either schema version. The publisher
 * (ReleaseContent) and the Studio oracle editor (the GameSystem aggregate) both call it, so a draft's
 * section is valid on its own after every edit under the same rules a release is published under.
 *
 * It checks only what the section holds: shape, field lists, dice coverage, unique keys across tables
 * and likelihood oracles, caps, nesting, entry keys and the random event table. What needs other
 * sections (an entry's sceneType and effects, a chaos tracker) stays in the full validation.
 */
final class OraclesSection
{
    /**
     * @param int $schemaVersion 1, or 2 for anything else
     *
     * @return array{tables: list<array<string, mixed>>, likelihood: list<array<string, mixed>>} the section in schema field order
     *
     * @throws InvalidReleaseContent with a path starting at "oracles"
     */
    public static function validate(mixed $oracles, int $schemaVersion): array
    {
        if (1 === $schemaVersion) {
            return ReleaseOracles::validate($oracles);
        }

        return ReleaseOracles::validate($oracles, ReleaseVersion2::ENTRY, ReleaseVersion2::CHAOS, ReleaseVersion2::LIKELIHOOD);
    }
}
