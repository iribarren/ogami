<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Shared\Application\Bus\QueryHandler;
use App\Studio\Domain\GameSystem\DraftReferences;
use App\Studio\Domain\GameSystem\GameSystemNotFound;

final readonly class GetGameSystemChecksHandler implements QueryHandler
{
    /** The sheet field types a check formula may read. */
    private const array READABLE_TYPES = ['number', 'track', 'derived'];

    public function __construct(
        private OwnedGameSystems $owned,
    ) {
    }

    /**
     * @throws GameSystemNotFound when the user has no such GameSystem
     */
    public function __invoke(GetGameSystemChecks $query): GameSystemChecksView
    {
        $gameSystem = $this->owned->get($query->ownerId, $query->key);
        $document = $gameSystem->document();
        $version1 = 1 === $gameSystem->schemaVersion();
        $checks = $version1 ? [] : $this->checks($document);

        return new GameSystemChecksView(
            $gameSystem->version(),
            $gameSystem->schemaVersion(),
            $checks,
            $version1 ? [] : $this->fields($document),
            $version1 ? [] : $this->trackers($document),
            $this->usages($document, $checks),
        );
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array<array-key, mixed>> the checks that are objects with a string key, in order
     */
    private function checks(array $document): array
    {
        return array_values(array_filter($this->objects($document['checks'] ?? null), static fn (array $check): bool => \is_string($check['key'] ?? null)));
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<CheckFieldView>
     */
    private function fields(array $document): array
    {
        $sheet = $document['sheet'] ?? null;
        $views = [];
        foreach ($this->objects(\is_array($sheet) ? $sheet['fields'] ?? null : null) as $field) {
            $type = $field['type'] ?? null;
            if (\is_string($field['key'] ?? null) && \is_string($type) && \in_array($type, self::READABLE_TYPES, true)) {
                $views[] = new CheckFieldView($field['key'], \is_string($field['label'] ?? null) ? $field['label'] : '', $type, 'track' === $type && \is_int($field['max'] ?? null) ? $field['max'] : null);
            }
        }

        return $views;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<CheckTrackerView>
     */
    private function trackers(array $document): array
    {
        $views = [];
        foreach ($this->objects($document['trackers'] ?? null) as $tracker) {
            if (!\is_string($tracker['key'] ?? null)) {
                continue;
            }

            $initial = 'clock' === ($tracker['kind'] ?? null) ? 0 : $tracker['initial'] ?? null;
            $views[] = new CheckTrackerView($tracker['key'], \is_string($tracker['name'] ?? null) ? $tracker['name'] : '', \is_int($initial) ? $initial : null);
        }

        return $views;
    }

    /**
     * @param array<string, mixed>          $document
     * @param list<array<array-key, mixed>> $checks   checks with a string key
     *
     * @return array<string, list<string>>
     */
    private function usages(array $document, array $checks): array
    {
        $usages = [];
        foreach ($checks as $check) {
            /** @var string $key checked by checks() */
            $key = $check['key'];
            $usages[$key] = DraftReferences::checkPathsTo($document, $key);
        }

        return $usages;
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
}
