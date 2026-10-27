<?php

declare(strict_types=1);

namespace App\Play\Domain\GameSystem\Sheet;

use App\Formula\Domain\DependencyOrder;
use App\Formula\Domain\InvalidFormula;

/**
 * The character sheet a GameSystem release defines (ADR 0022): its fields in layout order. A
 * release without fields has an empty template, and its Characters have a name and tags only. The
 * layout (ADR 0031) is null when the release declares no groups; the point budgets (ADR 0040,
 * 0043) are a keyed list, empty when it declares none; the currency (ADR 0045) is null without one.
 */
final readonly class SheetTemplate
{
    /** @var array<string, Field> */
    private array $fields;

    /** @var list<string> the keys of the derived fields, each after the derived fields it reads */
    private array $derivedOrder;

    /**
     * @param list<Field>       $fields  in definition order
     * @param list<PointBudget> $budgets in order
     *
     * @throws \InvalidArgumentException when two fields share a key, derived fields read each other in a cycle, two budgets share a key, a budget covers a field that is not a number field, a field is in two budgets or the currency is not a number field
     */
    public function __construct(array $fields = [], private ?SheetLayout $layout = null, private array $budgets = [], private ?Currency $currency = null)
    {
        $byKey = [];
        foreach ($fields as $field) {
            if (isset($byKey[$field->key])) {
                throw new \InvalidArgumentException(\sprintf('Sheet field key "%s" is listed twice.', $field->key));
            }

            $byKey[$field->key] = $field;
        }

        $this->fields = $byKey;

        $owners = [];
        $budgetKeys = [];
        foreach ($budgets as $budget) {
            if (isset($budgetKeys[$budget->key])) {
                throw new \InvalidArgumentException(\sprintf('Point budget key "%s" is used twice.', $budget->key));
            }

            $budgetKeys[$budget->key] = true;
            foreach ($budget->fields as $key) {
                if (!($byKey[$key] ?? null) instanceof NumberField) {
                    throw new \InvalidArgumentException(\sprintf('The point budget covers "%s", which is not a number field.', $key));
                }

                if (isset($owners[$key])) {
                    throw new \InvalidArgumentException(\sprintf('Field "%s" is in budgets "%s" and "%s".', $key, $owners[$key], $budget->key));
                }

                $owners[$key] = $budget->key;
            }
        }

        if ($currency instanceof Currency && !($byKey[$currency->field] ?? null) instanceof NumberField) {
            throw new \InvalidArgumentException(\sprintf('The currency is "%s", which is not a number field.', $currency->field));
        }

        $formulas = [];
        foreach ($byKey as $key => $field) {
            if ($field instanceof DerivedField) {
                $formulas[$key] = $field->formula;
            }
        }

        try {
            $this->derivedOrder = DependencyOrder::of($formulas);
        } catch (InvalidFormula $cycle) {
            throw new \InvalidArgumentException($cycle->getMessage(), 0, $cycle);
        }
    }

    /**
     * @return list<Field> in definition order
     */
    public function fields(): array
    {
        return array_values($this->fields);
    }

    public function layout(): ?SheetLayout
    {
        return $this->layout;
    }

    /**
     * @return list<PointBudget> in order; none when the release declares none
     */
    public function budgets(): array
    {
        return $this->budgets;
    }

    public function currency(): ?Currency
    {
        return $this->currency;
    }

    /**
     * The lowest amount of the currency a Character can hold: the field's min, else 0; null without a currency.
     */
    public function currencyFloor(): ?int
    {
        $field = $this->currency instanceof Currency ? $this->field($this->currency->field) : null;

        return $field instanceof NumberField ? $field->min ?? 0 : null;
    }

    public function field(string $key): ?Field
    {
        return $this->fields[$key] ?? null;
    }

    /**
     * The keys of the derived fields in the order to compute them: each after the derived fields it reads.
     *
     * @return list<string>
     */
    public function derivedOrder(): array
    {
        return $this->derivedOrder;
    }

    public function isEmpty(): bool
    {
        return [] === $this->fields;
    }
}
