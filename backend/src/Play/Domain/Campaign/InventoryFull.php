<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign;

/**
 * A shop purchase (ADR 0045) that would take an inventory field over its slots, its stacks or the
 * quantity a stack holds. A purchase never spills into another field, so the shop step does not
 * complete and the campaign stays as it was.
 */
final class InventoryFull extends \DomainException
{
    public static function slots(string $field, int $capacity, int $needed): self
    {
        return new self(\sprintf('Field "%s" holds %d slots: the purchase needs %d.', $field, $capacity, $needed));
    }

    public static function stacks(string $field, int $maxStacks, int $needed): self
    {
        return new self(\sprintf('Field "%s" holds %d %s: the purchase needs %d.', $field, $maxStacks, 1 === $maxStacks ? 'stack' : 'stacks', $needed));
    }

    public static function quantity(string $field, string $gear, int $max, int $needed): self
    {
        return new self(\sprintf('Field "%s" holds at most %d of "%s": the purchase needs %d.', $field, $max, $gear, $needed));
    }
}
