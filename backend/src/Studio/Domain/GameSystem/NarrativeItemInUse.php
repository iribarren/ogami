<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * A tracker, tag, fact slot or Scene Type cannot be renamed or deleted while the draft references
 * its key outside the item itself. The paths say where, e.g. "flows[0].phases[1].selection".
 */
final class NarrativeItemInUse extends \DomainException
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
