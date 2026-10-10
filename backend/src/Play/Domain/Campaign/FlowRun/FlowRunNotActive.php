<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * The command needs an active FlowRun: the campaign plays freely, or the Flow is complete.
 */
final class FlowRunNotActive extends \DomainException
{
    public static function none(): self
    {
        return new self('The campaign plays freely: it follows no Flow.');
    }

    public static function completed(): self
    {
        return new self('The Flow is complete: play goes on freely.');
    }
}
