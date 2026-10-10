<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * The result given does not complete the current step: another kind, an unknown option, another
 * table or other dice, or a blank answer.
 */
final class InvalidStepResult extends \DomainException
{
    public static function kind(string $step, StepKind $expected, StepKind $given): self
    {
        return new self(\sprintf('Step "%s" is a %s step: a %s result does not complete it.', $step, $expected->value, $given->value));
    }

    public static function unknownOption(string $step, string $option): self
    {
        return new self(\sprintf('Choice step "%s" has no option "%s".', $step, $option));
    }

    public static function otherTable(string $step, string $table): self
    {
        return new self(\sprintf('Step "%s" rolls on table "%s".', $step, $table));
    }

    public static function otherDice(string $step, string $dice): self
    {
        return new self(\sprintf('Step "%s" rolls %s.', $step, $dice));
    }

    public static function blankAnswer(string $step): self
    {
        return new self(\sprintf('Step "%s" needs an answer.', $step));
    }
}
