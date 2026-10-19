<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * While guidance is active the FlowRun starts the scenes: starting one by hand would leave the
 * guided scene empty. The player pauses guidance first to play freely.
 */
final class SceneCannotStartWhileGuided extends \DomainException
{
    public static function byHand(): self
    {
        return new self('The Flow guides this campaign and starts its scenes: pause guidance to start a scene by hand.');
    }
}
