<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

use App\Play\Domain\GameSystem\Flow\Phase;

/**
 * The phase boundary a hook Scene records (ADR 0018 decision 13). Scene opening and closing steps
 * run inside their own Scene, so they have no hook Scene.
 */
enum Hook: string
{
    case SessionOpening = 'sessionOpening';
    case SessionClosing = 'sessionClosing';
    case PhaseOpening = 'phaseOpening';
    case PhaseClosing = 'phaseClosing';
    case WorldTurn = 'worldTurn';

    /**
     * The default title of a hook Scene: "Session 3 begins", "Session 3 ends", "Act 2: The big
     * job" (the phase name alone without an act), "The big job ends", "The world moves". Cut to
     * the longest title a Scene takes.
     *
     * @param int   $sessionNumber the session the hook Scene starts in
     * @param Phase $phase         the phase that runs the hook
     */
    public function defaultTitle(int $sessionNumber, Phase $phase): string
    {
        $title = match ($this) {
            self::SessionOpening => \sprintf('Session %d begins', $sessionNumber),
            self::SessionClosing => \sprintf('Session %d ends', $sessionNumber),
            self::PhaseOpening => null === $phase->act ? $phase->name : \sprintf('%s: %s', $phase->act, $phase->name),
            self::PhaseClosing => \sprintf('%s ends', $phase->name),
            self::WorldTurn => 'The world moves',
        };

        return mb_substr($title, 0, Scene::MAX_TITLE_LENGTH);
    }
}
