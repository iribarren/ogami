<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Check;

/**
 * The picks sent with a push do not fit its cost (ADR 0041): each mark without an option whose field
 * still has an unmarked option takes the next pick, which must be one of those options.
 */
final class InvalidCheckPicks extends \DomainException
{
    public static function missing(string $field): self
    {
        return new self(\sprintf('The cost of the push marks an option of "%s": pick one.', $field));
    }

    /**
     * @param list<string> $available the options the field still has unmarked, in declared order
     */
    public static function notAvailable(string $field, string $pick, array $available): self
    {
        return new self(\sprintf('"%s" is not an unmarked option of "%s" (unmarked: %s).', $pick, $field, implode(', ', $available)));
    }

    /**
     * @param list<string> $unused the picks the cost did not take
     * @param list<string> $fields the fields the cost marks an option of, in cost order
     */
    public static function unused(array $unused, array $fields): self
    {
        return new self(\sprintf('The cost of the push takes no more picks (it picks among %s); unused: %s.', [] === $fields ? 'nothing' : implode(', ', $fields), implode(', ', $unused)));
    }
}
