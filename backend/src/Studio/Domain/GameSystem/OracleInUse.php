<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * An oracle, or an entry key of one, cannot be renamed, removed or deleted while the draft
 * references it. The paths say where, e.g. "flows[0].phases[1].sceneOpening[2]".
 */
final class OracleInUse extends \DomainException
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
