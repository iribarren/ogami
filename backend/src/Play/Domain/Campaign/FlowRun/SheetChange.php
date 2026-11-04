<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * What one sheet Effect did to a Character (ADR 0027): the field changed, its value before and after
 * (the current value for a track), and the dice rolled for it, if any.
 */
final readonly class SheetChange
{
    /**
     * @param ?string $diceExpression the normalized notation of the dice rolled, null when the value rolled none
     * @param ?int    $diceTotal      their total, null when the value rolled none
     */
    public function __construct(
        public string $field,
        public int $before,
        public int $after,
        public ?string $diceExpression = null,
        public ?int $diceTotal = null,
    ) {
    }

    /**
     * Whether the Effect changed nothing and rolled no dice. A change that rolled dice stays even when
     * the value held: the dice tell why nothing changed.
     */
    public function isNoOp(): bool
    {
        return $this->before === $this->after && null === $this->diceExpression && null === $this->diceTotal;
    }

    /**
     * The changes that changed something or rolled dice, in order: a journal entry records only those.
     *
     * @param list<SheetChange> $changes
     *
     * @return list<SheetChange>
     */
    public static function withoutNoOps(array $changes): array
    {
        return array_values(array_filter($changes, static fn (self $change): bool => !$change->isNoOp()));
    }

    /**
     * @return array{field: string, before: int, after: int, dice: ?array{expression: string, total: int}}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'before' => $this->before,
            'after' => $this->after,
            'dice' => null === $this->diceExpression || null === $this->diceTotal ? null : ['expression' => $this->diceExpression, 'total' => $this->diceTotal],
        ];
    }
}
