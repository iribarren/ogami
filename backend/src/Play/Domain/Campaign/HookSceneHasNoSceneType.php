<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * A hook Scene records a phase boundary: it has no Scene Type to switch.
 */
final class HookSceneHasNoSceneType extends \DomainException
{
    public static function toSwitch(?Hook $hook): self
    {
        return new self(\sprintf('The current scene is a %shook scene: only a scene of play has a Scene Type to switch.', $hook instanceof Hook ? '"'.$hook->value.'" ' : ''));
    }
}
