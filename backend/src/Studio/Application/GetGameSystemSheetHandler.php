<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Release\GameSystemReleaseRepository;

final readonly class GetGameSystemSheetHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
        private GameSystemReleaseRepository $releases,
    ) {
    }

    /**
     * @throws GameSystemNotFound when the user has no such GameSystem
     */
    public function __invoke(GetGameSystemSheet $query): GameSystemSheetView
    {
        $gameSystem = $this->owned->get($query->ownerId, $query->key);
        $document = $gameSystem->document();
        $sheet = $this->sheet($document);
        $latest = $this->releases->latestFor($query->key);
        $budgets = 1 === $gameSystem->schemaVersion() ? [] : $this->pointBudgets($sheet);

        return new GameSystemSheetView(
            $gameSystem->version(),
            $gameSystem->schemaVersion(),
            $this->int($sheet['columns'] ?? null, 1),
            $this->groups($sheet['groups'] ?? null),
            1 === $gameSystem->schemaVersion() ? [] : $this->fields($sheet),
            1 === $gameSystem->schemaVersion() ? [] : $this->trackers($document),
            $latest instanceof \App\Studio\Domain\Release\GameSystemRelease ? $this->keys($this->fields($this->sheet($latest->content()->toArray()))) : [],
            $budgets,
            1 === $gameSystem->schemaVersion() ? [] : $this->gear($document['gear'] ?? null),
            1 === $gameSystem->schemaVersion() ? null : $this->currency($sheet['currency'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<array-key, mixed>
     */
    private function sheet(array $document): array
    {
        return \is_array($document['sheet'] ?? null) ? $document['sheet'] : [];
    }

    /**
     * @param array<array-key, mixed> $sheet
     *
     * @return list<SheetPointBudgetView> the stored list, else the legacy budget as "points"; a budget without a string key (in a list) or an integer total is left out
     */
    private function pointBudgets(array $sheet): array
    {
        $stored = $this->objects($sheet['pointBudgets'] ?? null);
        if ([] === $stored && \is_array($sheet['pointBudget'] ?? null)) {
            $stored = [['key' => 'points', ...$sheet['pointBudget']]];
        }

        $views = [];
        foreach ($stored as $budget) {
            if (\is_string($budget['key'] ?? null) && \is_int($budget['total'] ?? null)) {
                $views[] = new SheetPointBudgetView(
                    $budget['key'],
                    \is_string($budget['label'] ?? null) ? $budget['label'] : null,
                    $budget['total'],
                    array_values(array_filter(\is_array($budget['fields'] ?? null) ? $budget['fields'] : [], \is_string(...))),
                    \is_int($budget['min'] ?? null) ? $budget['min'] : null,
                    \is_int($budget['max'] ?? null) ? $budget['max'] : null,
                );
            }
        }

        return $views;
    }

    /**
     * @return list<SheetGearEntryView> the entries in order; one without a string key and name or an integer cost is left out
     */
    private function gear(mixed $gear): array
    {
        $views = [];
        foreach ($this->objects($gear) as $entry) {
            if (\is_string($entry['key'] ?? null) && \is_string($entry['name'] ?? null) && \is_int($entry['cost'] ?? null)) {
                $views[] = new SheetGearEntryView(
                    $entry['key'],
                    $entry['name'],
                    $entry['cost'],
                    $this->int($entry['slots'] ?? null, 1),
                    \is_string($entry['category'] ?? null) ? $entry['category'] : null,
                    \is_string($entry['description'] ?? null) ? $entry['description'] : null,
                );
            }
        }

        return $views;
    }

    private function currency(mixed $currency): ?SheetCurrencyView
    {
        return \is_array($currency) && \is_string($currency['field'] ?? null)
            ? new SheetCurrencyView($currency['field'], \is_string($currency['unit'] ?? null) ? $currency['unit'] : null)
            : null;
    }

    /**
     * @param array<array-key, mixed> $sheet
     *
     * @return list<array<array-key, mixed>> the fields that are objects with a string key, in order
     */
    private function fields(array $sheet): array
    {
        return array_values(array_filter($this->objects($sheet['fields'] ?? null), static fn (array $field): bool => \is_string($field['key'] ?? null)));
    }

    /**
     * @param list<array<array-key, mixed>> $fields fields with a string key
     *
     * @return list<string>
     */
    private function keys(array $fields): array
    {
        return array_values(array_filter(array_column($fields, 'key'), \is_string(...)));
    }

    /**
     * @return list<SheetGroupView>|null null when no group with a name is declared
     */
    private function groups(mixed $groups): ?array
    {
        $views = [];
        foreach ($this->objects($groups) as $group) {
            if (\is_string($group['name'] ?? null)) {
                $views[] = new SheetGroupView($group['name'], $this->int($group['columns'] ?? null, 1), $this->int($group['span'] ?? null, 1));
            }
        }

        return [] === $views ? null : $views;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<SheetTrackerView>
     */
    private function trackers(array $document): array
    {
        $views = [];
        foreach ($this->objects($document['trackers'] ?? null) as $tracker) {
            if (\is_string($tracker['key'] ?? null)) {
                $max = \is_int($tracker['max'] ?? null) ? $tracker['max'] : $tracker['segments'] ?? null;
                $views[] = new SheetTrackerView($tracker['key'], \is_string($tracker['name'] ?? null) ? $tracker['name'] : '', \is_int($max) ? $max : null);
            }
        }

        return $views;
    }

    /**
     * The object items of a list, in order; anything else is skipped.
     *
     * @return list<array<array-key, mixed>>
     */
    private function objects(mixed $items): array
    {
        return \is_array($items) && array_is_list($items) ? array_values(array_filter($items, \is_array(...))) : [];
    }

    private function int(mixed $value, int $default): int
    {
        return \is_int($value) ? $value : $default;
    }
}
