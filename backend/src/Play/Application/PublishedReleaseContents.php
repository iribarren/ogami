<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;

/**
 * Port to what a published release holds beyond the rules Play plays with: its whole content
 * document and its card images, which the table shows (ADR 0054). Published releases only, never
 * Studio drafts (ADR 0010); who may read them is decided by the caller.
 */
interface PublishedReleaseContents
{
    /**
     * @throws GameSystemReleaseNotFound when no published release matches
     * @throws InvalidGameSystemRelease  when the release no longer parses
     */
    public function content(string $gameSystemKey, int $version): ReleaseContentView;

    /**
     * The image only when this exact release pinned it: not another release of the GameSystem, not the draft.
     *
     * @throws GameSystemReleaseNotFound when no published release matches
     * @throws CardImageNotFound         when the release did not pin an image with this hash
     */
    public function cardImage(string $gameSystemKey, int $version, string $sha256): CardImageBytes;
}
