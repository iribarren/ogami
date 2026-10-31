<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

/**
 * One option out of a list the release declares, such as a stance or an origin (ADR 0048). The value
 * is the key of the chosen option, or null while none is chosen. A Character starts with the default
 * option, or none. No formula reads it and no effect changes it.
 */
final readonly class SelectField extends Field
{
    /** @var list<SelectOption> */
    public array $options;

    /**
     * @param list<SelectOption> $options in declared order
     *
     * @throws \InvalidArgumentException when there is no option, two options share a key or the default is not an option
     */
    public function __construct(
        string $key,
        string $label,
        ?string $hint = null,
        ?string $group = null,
        array $options = [],
        public ?string $default = null,
        int $span = 1,
    ) {
        if ([] === $options) {
            throw new \InvalidArgumentException(\sprintf('Select field "%s" declares no option.', $key));
        }

        $keys = array_map(static fn (SelectOption $option): string => $option->key, $options);
        if (\count(array_unique($keys)) !== \count($keys)) {
            throw new \InvalidArgumentException(\sprintf('Select field "%s" lists an option key twice.', $key));
        }

        if (null !== $default && !\in_array($default, $keys, true)) {
            throw new \InvalidArgumentException(\sprintf('Select field "%s" has a default that is not one of its options.', $key));
        }

        $this->options = $options;
        parent::__construct($key, $label, $hint, $group, $span);
    }

    public function has(string $optionKey): bool
    {
        return array_any($this->options, static fn ($option): bool => $option->key === $optionKey);
    }
}
