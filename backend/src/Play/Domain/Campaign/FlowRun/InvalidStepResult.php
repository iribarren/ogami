<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * The result given does not complete the current step: another kind, an unknown option, another
 * table or other dice, a blank answer, fields that do not belong to the step's kind, items a pick
 * step cannot take, or a shop transaction line the shop cannot trade.
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

    public static function notOnSale(string $step, string $gear): self
    {
        return new self(\sprintf('Shop step "%s" has no "%s" on sale.', $step, $gear));
    }

    public static function shopQuantity(string $step, string $gear, int $quantity, int $max): self
    {
        return new self(\sprintf('Shop step "%s" trades 1 to %d units of "%s", got %d.', $step, $max, $gear, $quantity));
    }

    public static function shopLineTwice(string $step, string $gear): self
    {
        return new self(\sprintf('Shop step "%s" lists "%s" twice.', $step, $gear));
    }

    public static function shopDoesNotBuy(string $step): self
    {
        return new self(\sprintf('Shop step "%s" does not buy.', $step));
    }

    public static function notAnInventory(string $step, string $field): self
    {
        return new self(\sprintf('Shop step "%s" cannot buy from field "%s": it is no inventory field.', $step, $field));
    }

    public static function overSell(string $step, int $quantity, string $gear, string $field, int $held): self
    {
        return new self(\sprintf('Shop step "%s" cannot buy %d "%s" from field "%s", which holds %d.', $step, $quantity, $gear, $field, $held));
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

    public static function fixedDifficulty(string $step, int $difficulty): self
    {
        return new self(\sprintf('Step "%s" fixes the check difficulty at %d: it takes no other.', $step, $difficulty));
    }

    public static function notInTheSet(string $step, string $check): self
    {
        return new self(\sprintf('Check step "%s" has no check "%s" in its set.', $step, $check));
    }

    public static function notInTheCast(string $step, string $id): self
    {
        return new self(\sprintf('Check step "%s" rolls for a Character in the scene (in the session party outside one): Character "%s" is not.', $step, $id));
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

    /** A choice or prompt step that changes a sheet names a Character the campaign lacks. */
    public static function unknownSheetCharacter(string $step, StepKind $kind, string $id): self
    {
        return new self(\sprintf('%s step "%s" has no Character "%s" in the campaign.', ucfirst($kind->value), $step, $id));
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
