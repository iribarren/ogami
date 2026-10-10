<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * The scene pick does not offer that Scene Type, or that way of picking: a sequence offers its
 * next type, a player selection its types, an oracle selection a roll on its table, and a forced
 * next Scene Type only itself.
 */
final class SceneTypeNotOffered extends \DomainException
{
    public static function withKey(string $sceneType): self
    {
        return new self(\sprintf('The scene pick does not offer Scene Type "%s".', $sceneType));
    }

    public static function rollTheTable(string $table): self
    {
        return new self(\sprintf('This scene pick rolls on table "%s".', $table));
    }

    public static function noRoll(): self
    {
        return new self('This scene pick does not roll on a table.');
    }

    public static function entryWithoutSceneType(string $table): self
    {
        return new self(\sprintf('The rolled entry of table "%s" names no Scene Type.', $table));
    }
}
