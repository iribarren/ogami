<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem;

/**
 * A publisher's logo the credits of a release ask Play to show beside the notice (ADR 0044). A closed
 * set: a release never carries an image or a URL to one, and the SPA ships the image of each badge. A
 * new badge is a new case and its image, added together.
 */
enum CreditsBadge: string
{
    case DarkPack = 'dark-pack';
}
