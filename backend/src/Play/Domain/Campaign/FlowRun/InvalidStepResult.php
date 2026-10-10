<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * The result given does not complete the current step: another kind, an unknown option, another
 * table or other dice, a blank answer, or fields that do not belong to the step's kind.
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

    public static function unexpectedField(string $step, StepKind $kind, string $field): self
    {
        return new self(\sprintf('Step "%s" is a %s step: it takes no "%s".', $step, $kind->value, $field));
    }

    public static function missingField(string $step, StepKind $kind, string $field): self
    {
        return new self(\sprintf('Step "%s" is a %s step: it needs a "%s".', $step, $kind->value, $field));
    }

    public static function fixedLikelihood(string $step, string $likelihood): self
    {
        return new self(\sprintf('Step "%s" asks at likelihood "%s": it takes no other.', $step, $likelihood));
    }

    public static function conditionStep(string $step): self
    {
        return new self(\sprintf('Step "%s" is a condition step: it advances by itself and cannot be completed.', $step));
    }
}
