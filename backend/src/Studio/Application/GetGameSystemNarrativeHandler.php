<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\DraftReferences;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\GameSystem\NarrativeSection;

final readonly class GetGameSystemNarrativeHandler implements QueryHandler
{
    public function __construct(
        private OwnedGameSystems $owned,
    ) {
    }

    /**
     * @throws GameSystemNotFound when the user has no such GameSystem
     */
    public function __invoke(GetGameSystemNarrative $query): GameSystemNarrativeView
    {
        $gameSystem = $this->owned->get($query->ownerId, $query->key);
        $document = $gameSystem->document();
        $narrative = 1 !== $gameSystem->schemaVersion();

        return new GameSystemNarrativeView(
            $gameSystem->version(),
            $gameSystem->schemaVersion(),
            $narrative ? $this->items($document, NarrativeSection::Trackers) : [],
            $narrative ? $this->items($document, NarrativeSection::Tags) : [],
            $narrative ? $this->items($document, NarrativeSection::FactSlots) : [],
            $narrative ? $this->items($document, NarrativeSection::SceneTypes) : [],
            $narrative ? $this->items($document, NarrativeSection::Flows) : [],
            $this->catalog($document),
        );
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<NarrativeItemView>
     */
    private function items(array $document, NarrativeSection $section): array
    {
        $views = [];
        foreach ($this->objects($document[$section->value] ?? null) as $item) {
            if (\is_string($item['key'] ?? null)) {
                // The same places that block a rename or a delete: a Scene Type's own steps naming it do not count.
                $views[] = new NarrativeItemView($item['key'], $item, DraftReferences::narrativePathsTo($document, $section, $item['key']));
            }
        }

        return $views;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function catalog(array $document): NarrativeCatalogView
    {
        $oracles = \is_array($document['oracles'] ?? null) ? $document['oracles'] : [];
        $sheet = \is_array($document['sheet'] ?? null) ? $document['sheet'] : [];

        $tables = [];
        foreach ($this->keyed($oracles['tables'] ?? null) as [$key, $table]) {
            $tables[] = ['key' => $key, 'name' => $this->text($table, 'name'), 'entries' => $this->keys($table['entries'] ?? null)];
        }

        $likelihood = [];
        foreach ($this->keyed($oracles['likelihood'] ?? null) as [$key, $oracle]) {
            $likelihood[] = ['key' => $key, 'name' => $this->text($oracle, 'name'), 'levels' => $this->keys($oracle['levels'] ?? null)];
        }

        $checks = [];
        foreach ($this->keyed($document['checks'] ?? null) as [$key, $check]) {
            $checks[] = ['key' => $key, 'label' => $this->text($check, 'label'), 'bands' => $this->keys($check['bands'] ?? null), 'difficulty' => $this->difficulty($check['difficulty'] ?? null)];
        }

        $fields = [];
        foreach ($this->keyed($sheet['fields'] ?? null) as [$key, $field]) {
            if (\in_array($field['type'] ?? null, ['number', 'track'], true)) {
                $fields[] = ['key' => $key, 'label' => $this->text($field, 'label')];
            }
        }

        return new NarrativeCatalogView($tables, $likelihood, $checks, $fields);
    }

    /**
     * @return array{default: int, min: int|null, max: int|null}|null null when the check has no usable difficulty
     */
    private function difficulty(mixed $difficulty): ?array
    {
        if (!\is_array($difficulty) || !\is_int($difficulty['default'] ?? null)) {
            return null;
        }

        return [
            'default' => $difficulty['default'],
            'min' => \is_int($difficulty['min'] ?? null) ? $difficulty['min'] : null,
            'max' => \is_int($difficulty['max'] ?? null) ? $difficulty['max'] : null,
        ];
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

    /**
     * The object items of a list that have a string key, each with it.
     *
     * @return list<array{string, array<array-key, mixed>}>
     */
    private function keyed(mixed $items): array
    {
        $keyed = [];
        foreach ($this->objects($items) as $item) {
            if (\is_string($item['key'] ?? null)) {
                $keyed[] = [$item['key'], $item];
            }
        }

        return $keyed;
    }

    /**
     * @return list<string> the string keys of the object items of a list
     */
    private function keys(mixed $items): array
    {
        return array_column($this->keyed($items), 0);
    }

    /**
     * @param array<array-key, mixed> $item
     */
    private function text(array $item, string $field): string
    {
        return \is_string($item[$field] ?? null) ? $item[$field] : '';
    }
}
