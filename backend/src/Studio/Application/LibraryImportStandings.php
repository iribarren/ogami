<?php

declare(strict_types=1);

namespace App\Studio\Application;

use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\LibraryItems;
use App\Studio\Domain\Release\ReleaseContent;

/**
 * Where each library import of a draft stands against its pack (ADR 0033), computed when asked and
 * never stored. The imports list, the draft detail and the draft list all read it, so they agree.
 */
final readonly class LibraryImportStandings
{
    public function __construct(
        private LibraryPacks $packs,
    ) {
    }

    /**
     * @return list<LibraryImportView> the draft's imports in the order imported
     *
     * @throws \UnexpectedValueException when the draft's stored imports are malformed
     */
    public function of(GameSystem $gameSystem): array
    {
        return $this->ofEach([$gameSystem])[$gameSystem->key()];
    }

    /**
     * How many imports of the draft changed or went away in the library; the ones edited locally do
     * not count. A draft whose stored imports are malformed counts 0, so it does not break the list
     * or the detail it appears in; the imports read itself stays fail-closed.
     */
    public function changesOf(GameSystem $gameSystem): int
    {
        return $this->changesOfEach([$gameSystem])[$gameSystem->key()];
    }

    /**
     * The change counts of several drafts, each pack looked up once for all of them.
     *
     * @param iterable<GameSystem> $gameSystems
     *
     * @return array<string, int> by game system key
     */
    public function changesOfEach(iterable $gameSystems): array
    {
        $readable = [];
        $counts = [];
        foreach ($gameSystems as $gameSystem) {
            try {
                $gameSystem->libraryImports();
                $readable[] = $gameSystem;
            } catch (\UnexpectedValueException) {
                $counts[$gameSystem->key()] = 0;
            }
        }

        foreach ($this->ofEach($readable) as $key => $views) {
            $counts[$key] = self::countChanges($views);
        }

        return $counts;
    }

    /**
     * The standings of several drafts, each pack looked up once for all of them; a draft that
     * imported nothing loads no pack.
     *
     * @param iterable<GameSystem> $gameSystems
     *
     * @return array<string, list<LibraryImportView>> by game system key
     */
    public function ofEach(iterable $gameSystems): array
    {
        /** @var array<string, array{GameSystem, LibraryItems}|null> $packs each pack is loaded once */
        $packs = [];
        $standings = [];
        foreach ($gameSystems as $gameSystem) {
            $draft = LibraryItems::of($gameSystem->document());
            $views = [];
            foreach ($gameSystem->libraryImports() as $import) {
                if (!\array_key_exists($import->pack, $packs)) {
                    $pack = $this->packs->find($import->pack);
                    $packs[$import->pack] = $pack instanceof GameSystem ? [$pack, LibraryItems::of($pack->document())] : null;
                }

                $source = null === $packs[$import->pack] ? null : $packs[$import->pack][1]->find($import->ref());
                $own = $draft->find($import->ref());

                $views[] = new LibraryImportView(
                    $import->section,
                    $import->key,
                    LibraryItems::labelOf($own['item'] ?? null, $import->key),
                    null === $packs[$import->pack] ? null : ['key' => $import->pack, 'name' => $packs[$import->pack][0]->name()],
                    match (true) {
                        null === $source => LibraryImportStatus::Removed,
                        ReleaseContent::itemHash($source['item']) !== $import->hash => LibraryImportStatus::Changed,
                        default => LibraryImportStatus::Current,
                    },
                    null !== $own && ReleaseContent::itemHash($own['item']) !== $import->hash,
                );
            }
            $standings[$gameSystem->key()] = $views;
        }

        return $standings;
    }

    /**
     * @param list<LibraryImportView> $views
     */
    public static function countChanges(array $views): int
    {
        return \count(array_filter($views, static fn (LibraryImportView $view): bool => LibraryImportStatus::Current !== $view->status));
    }
}
