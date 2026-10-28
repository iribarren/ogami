<?php

declare(strict_types=1);

namespace App\Studio\Domain\GameSystem;

/**
 * A gear entry cannot be deleted while a shop step of the draft offers it by key. The paths say
 * where, e.g. "sceneTypes[20].play[0].offer.gear[0]".
 */
final class GearInUse extends \DomainException
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
