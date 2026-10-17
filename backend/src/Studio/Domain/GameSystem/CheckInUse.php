<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * A check cannot be renamed or deleted while a check step names it, and a band cannot be dropped
 * from a check while an outcome of a step naming the check names it. The paths say where, e.g.
 * "sceneTypes[0].play[1].check" or "sceneTypes[0].play[1].outcomes[0].band".
 */
final class CheckInUse extends \DomainException
{
    /**
     * @param list<string> $paths
     */
    private function __construct(string $message, private readonly array $paths)
    {
        parent::__construct($message);
    }

    /**
     * @param list<string> $paths
     */
    public static function at(string $what, array $paths): self
    {
        return new self(\sprintf('%s is used at %s.', $what, implode(', ', $paths)), $paths);
    }

    /**
     * @return list<string> where the draft references it
     */
    public function paths(): array
    {
        return $this->paths;
    }
}
