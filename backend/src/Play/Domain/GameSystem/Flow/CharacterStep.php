<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Flow;

/**
 * The player brings a Character of the campaign: one that exists (created from the step if none
 * does, see ADR 0022). Its name is the step's answer, and the Character joins the party and the
 * scene's cast as a one-item pick does.
 */
final readonly class CharacterStep extends Step
{
}
