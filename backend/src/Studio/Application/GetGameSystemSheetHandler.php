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

        return new GameSystemSheetView(
            $gameSystem->version(),
            $gameSystem->schemaVersion(),
            $this->int($sheet['columns'] ?? null, 1),
            $this->groups($sheet['groups'] ?? null),
            1 === $gameSystem->schemaVersion() ? [] : $this->fields($sheet),
            1 === $gameSystem->schemaVersion() ? [] : $this->trackers($document),
            $latest instanceof \App\Studio\Domain\Release\GameSystemRelease ? $this->keys($this->fields($this->sheet($latest->content()->toArray()))) : [],
            1 === $gameSystem->schemaVersion() ? null : $this->pointBudget($sheet['pointBudget'] ?? null),
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
     * @return SheetPointBudgetView|null null without a budget, or when the stored one has no integer total
     */
    private function pointBudget(mixed $budget): ?SheetPointBudgetView
    {
        if (!\is_array($budget) || !\is_int($budget['total'] ?? null)) {
            return null;
        }

        return new SheetPointBudgetView(
            \is_string($budget['label'] ?? null) ? $budget['label'] : null,
            $budget['total'],
            array_values(array_filter(\is_array($budget['fields'] ?? null) ? $budget['fields'] : [], \is_string(...))),
            \is_int($budget['min'] ?? null) ? $budget['min'] : null,
            \is_int($budget['max'] ?? null) ? $budget['max'] : null,
        );
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
