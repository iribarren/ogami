<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * The result given does not complete the current step: another kind, an unknown option, another
 * table or other dice, a blank answer, fields that do not belong to the step's kind, or items a pick
 * step cannot take.
 */
final class InvalidStepResult extends \DomainException
{
    public static function kind(string $step, StepKind $expected, StepKind $given): self
    {
        return new self(\sprintf('Step "%s" is %s step: %s result does not complete it.', $step, self::aOrAn($expected), self::aOrAn($given)));
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
        return new self(\sprintf('Step "%s" is %s step: it takes no "%s".', $step, self::aOrAn($kind), $field));
    }

    public static function missingField(string $step, StepKind $kind, string $field): self
    {
        return new self(\sprintf('Step "%s" is %s step: it needs "%s".', $step, self::aOrAn($kind), $field));
    }

    public static function fixedLikelihood(string $step, string $likelihood): self
    {
        return new self(\sprintf('Step "%s" asks at likelihood "%s": it takes no other.', $step, $likelihood));
    }

    public static function conditionStep(string $step): self
    {
        return new self(\sprintf('Step "%s" is a condition step: it advances by itself and cannot be completed.', $step));
    }

    public static function otherItems(string $step, string $expected): self
    {
        return new self(\sprintf('Step "%s" picks %ss.', $step, $expected));
    }

    public static function notACandidate(string $step, string $id): self
    {
        return new self(\sprintf('Pick step "%s" has no candidate "%s".', $step, $id));
    }

    public static function repeatedPick(string $step, string $id): self
    {
        return new self(\sprintf('Pick step "%s" was given "%s" twice.', $step, $id));
    }

    public static function unknownCharacter(string $step, string $id): self
    {
        return new self(\sprintf('Character step "%s" has no Character "%s" in the campaign.', $step, $id));
    }

    public static function pickCount(string $step, int $min, int $max, int $given): self
    {
        $takes = $min === $max ? \sprintf('%d', $min) : \sprintf('%d to %d', $min, $max);

        return new self(\sprintf('Pick step "%s" takes %s %s, got %d.', $step, $takes, 1 === $max ? 'item' : 'items', $given));
    }

    public static function tooFewCandidates(string $step, int $min, int $candidates): self
    {
        return new self(\sprintf('Pick step "%s" needs %d candidates, has %d: nothing can be picked.', $step, $min, $candidates));
    }

    /**
     * The kind with its indefinite article: "an oracle", "a table".
     */
    private static function aOrAn(StepKind $kind): string
    {
        return (StepKind::Oracle === $kind ? 'an ' : 'a ').$kind->value;
    }
}
