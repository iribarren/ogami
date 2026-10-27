<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Play\Domain\GameSystem\Check\CheckPush;

/**
 * The push of a pool check as a player sees it before pushing: the label of its cost and the field
 * of each option the player picks (a mark without an option), in cost order, and the most failed
 * regular dice it re-rolls (ADR 0044). What the cost changes stays in the release.
 */
final readonly class CheckPushView
{
    /**
     * @param list<string> $picks   the sheet field of each mark without an option, in cost order
     * @param ?int         $maxDice the most failed regular dice a push re-rolls; null for every one of them
     */
    public function __construct(
        public string $label,
        public array $picks,
        public ?int $maxDice = null,
    ) {
    }

    public static function of(CheckPush $push): self
    {
        return new self($push->label, $push->picks(), $push->maxDice);
    }
}
