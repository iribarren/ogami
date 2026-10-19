<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * Something the Flow decided on its own while a FlowRun command ran, as the player should read it
 * afterwards: the label of a band taken, a Tracker that changed, a Scene Type switch, the Scene
 * Type forced for the next scene, the end of the phase. Names are those of the pinned release at
 * the time. A Campaign collects the notes of a command, in apply order, and hands them out once
 * (Campaign::takeFlowNotes()); they are never part of the FlowRun's state.
 *
 * @phpstan-type FlowNoteData array<string, int|string>
 */
final readonly class FlowNote
{
    /**
     * @param FlowNoteData $data the canonical shape of the journal's flowOutcome note: its kind and fields
     */
    private function __construct(private array $data)
    {
    }

    public static function band(string $label): self
    {
        return new self(['kind' => 'band', 'label' => $label]);
    }

    public static function tracker(string $key, string $name, int $from, int $to): self
    {
        return new self(['kind' => 'tracker', 'key' => $key, 'name' => $name, 'from' => $from, 'to' => $to]);
    }

    public static function switch(string $from, string $to): self
    {
        return new self(['kind' => 'switch', 'from' => $from, 'to' => $to]);
    }

    public static function nextScene(string $sceneType): self
    {
        return new self(['kind' => 'nextScene', 'sceneType' => $sceneType]);
    }

    public static function phaseEnd(string $phase): self
    {
        return new self(['kind' => 'phaseEnd', 'phase' => $phase]);
    }

    /**
     * @return FlowNoteData
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
