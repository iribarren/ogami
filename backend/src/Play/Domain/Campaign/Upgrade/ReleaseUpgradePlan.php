<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Upgrade;

use App\Play\Domain\Campaign\PinnedRelease;

/**
 * What upgrading a campaign to a newer release of its GameSystem changes (ADR 0035): the preview the
 * player confirms. It is immutable and deterministic: the same campaign and releases give the same
 * plan, so its fingerprint stands for exactly this change.
 */
final readonly class ReleaseUpgradePlan
{
    /**
     * @param list<CharacterUpgrade> $characters Characters in campaign order, only those that change
     * @param list<DroppedTags>      $npcs       NPCs that lose release Tags, in creation order
     * @param list<DroppedTags>      $threads    Threads that lose release Tags, in creation order
     * @param list<DroppedTags>      $places     Places that lose release Tags, in creation order
     * @param list<TrackerChange>    $trackers   by Tracker key
     * @param list<UnslottedFact>    $facts      in creation order
     */
    public function __construct(
        public PinnedRelease $from,
        public PinnedRelease $to,
        public array $characters,
        public array $npcs,
        public array $threads,
        public array $places,
        public array $trackers,
        public array $facts,
        public FlowChange $flow,
    ) {
    }

    /**
     * The SHA-256 of the plan's canonical JSON: keys in a fixed order whatever order the stored
     * values came in, so equal plans have equal fingerprints.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode(self::canonical($this->toArray()), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->release($this->from),
            'to' => $this->release($this->to),
            'characters' => array_map(static fn (CharacterUpgrade $character): array => $character->toArray(), $this->characters),
            'npcs' => array_map(static fn (DroppedTags $npc): array => $npc->toArray(), $this->npcs),
            'threads' => array_map(static fn (DroppedTags $thread): array => $thread->toArray(), $this->threads),
            'places' => array_map(static fn (DroppedTags $place): array => $place->toArray(), $this->places),
            'trackers' => array_map(static fn (TrackerChange $tracker): array => $tracker->toArray(), $this->trackers),
            'facts' => array_map(static fn (UnslottedFact $fact): array => $fact->toArray(), $this->facts),
            'flow' => $this->flow->toArray(),
        ];
    }

    /**
     * @return array{gameSystemKey: string, releaseVersion: int, gameSystemName: string}
     */
    private function release(PinnedRelease $release): array
    {
        return ['gameSystemKey' => $release->gameSystemKey(), 'releaseVersion' => $release->releaseVersion(), 'gameSystemName' => $release->gameSystemName()];
    }

    /**
     * Sorts the keys of every map, so stored values that came back in another key order (JSONB) match.
     */
    private static function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
