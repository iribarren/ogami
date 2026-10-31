<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * A campaign or session is full. The limits keep one campaign's play history bounded.
 */
final class CampaignLimitReached extends \DomainException
{
    public static function sessions(int $max): self
    {
        return new self(\sprintf('A campaign holds at most %d sessions.', $max));
    }

    public static function scenes(int $max): self
    {
        return new self(\sprintf('A session holds at most %d scenes.', $max));
    }

    public static function npcs(int $max): self
    {
        return new self(\sprintf('A campaign holds at most %d NPCs.', $max));
    }

    public static function threads(int $max): self
    {
        return new self(\sprintf('A campaign holds at most %d Threads.', $max));
    }

    public static function places(int $max): self
    {
        return new self(\sprintf('A campaign holds at most %d Places.', $max));
    }

    public static function facts(int $max): self
    {
        return new self(\sprintf('A campaign holds at most %d facts.', $max));
    }

    public static function characters(int $max): self
    {
        return new self(\sprintf('A campaign holds at most %d Characters.', $max));
    }
}
