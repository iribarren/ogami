<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

/**
 * A set of Conditions a Character can be marked with, such as Hurt or Shaken (ADR 0041). The release
 * declares the options; the value is the list of marked option keys, each once, in the order the
 * options are declared. A formula reads the count marked.
 */
final readonly class ConditionsField extends Field
{
    /** @var list<ConditionOption> */
    public array $options;

    /**
     * @param list<ConditionOption> $options in declared order
     *
     * @throws \InvalidArgumentException when there is no option or two options share a key
     */
    public function __construct(
        string $key,
        string $label,
        ?string $hint = null,
        ?string $group = null,
        array $options = [],
        int $span = 1,
    ) {
        if ([] === $options) {
            throw new \InvalidArgumentException(\sprintf('Conditions field "%s" declares no option.', $key));
        }

        $keys = array_map(static fn (ConditionOption $option): string => $option->key, $options);
        if (\count(array_unique($keys)) !== \count($keys)) {
            throw new \InvalidArgumentException(\sprintf('Conditions field "%s" lists an option key twice.', $key));
        }

        $this->options = $options;
        parent::__construct($key, $label, $hint, $group, $span);
    }

    /**
     * @return list<string> the option keys in declared order
     */
    public function optionKeys(): array
    {
        return array_map(static fn (ConditionOption $option): string => $option->key, $this->options);
    }

    public function has(string $optionKey): bool
    {
        return \in_array($optionKey, $this->optionKeys(), true);
    }

    /**
     * The marked keys the field declares, each once, in declared order: what a value is stored as.
     *
     * @param list<string> $marked
     *
     * @return list<string>
     */
    public function ordered(array $marked): array
    {
        return array_values(array_filter($this->optionKeys(), static fn (string $key): bool => \in_array($key, $marked, true)));
    }
}
