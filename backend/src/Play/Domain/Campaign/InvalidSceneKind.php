<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * A Scene's kind and hook disagree: a hook Scene names its hook, a scene of play has none.
 */
final class InvalidSceneKind extends \DomainException
{
    public static function hookSceneWithoutHook(): self
    {
        return new self('A hook scene needs its hook.');
    }

    public static function sceneOfPlayWithHook(Hook $hook): self
    {
        return new self(\sprintf('A scene of play has no hook, "%s" given.', $hook->value));
    }
}
