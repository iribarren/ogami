<?php

declare(strict_types=1);

namespace App\Formula\Domain\Expression;

use App\Formula\Domain\ValueResolver;

/**
 * A call of `min` or `max`; the Parser checks the number of arguments.
 */
final readonly class Call implements Node
{
    /**
     * @param non-empty-list<Node> $arguments
     */
    public function __construct(
        private string $function,
        private array $arguments,
    ) {
    }

    public function evaluate(ValueResolver $resolver): int
    {
        $values = array_map(static fn (Node $argument): int => $argument->evaluate($resolver), $this->arguments);

        return 'min' === $this->function ? min($values) : max($values);
    }

    public function references(): array
    {
        return array_merge(...array_map(static fn (Node $argument): array => $argument->references(), $this->arguments));
    }
}
