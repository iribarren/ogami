<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * The command needs a FlowRun in another status: the campaign plays freely, guidance is paused (or
 * not), or the Flow is complete.
 */
final class FlowRunNotActive extends \DomainException
{
    public static function none(): self
    {
        return new self('The campaign plays freely: it follows no Flow.');
    }

    public static function toPlay(FlowRunStatus $status): self
    {
        return new self(FlowRunStatus::Paused === $status ? 'Guidance is paused: resume it first.' : 'The Flow is complete: play goes on freely.');
    }

    public static function toPause(FlowRunStatus $status): self
    {
        return new self(FlowRunStatus::Paused === $status ? 'Guidance is already paused.' : 'The Flow is complete: there is no guidance to pause.');
    }

    public static function toResume(FlowRunStatus $status): self
    {
        return new self(FlowRunStatus::Active === $status ? 'Guidance is not paused.' : 'The Flow is complete: there is no guidance to resume.');
    }
}
