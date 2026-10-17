<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * What a draft aggregate is: a GameSystem that publishes releases, or a library pack of generic
 * content that drafts import from (ADR 0033). The values are stored.
 */
enum GameSystemKind: string
{
    case GameSystem = 'game-system';
    case LibraryPack = 'library-pack';
}
