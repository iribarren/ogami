<?php

declare(strict_types=1);

namespace App\Play\Application;

use App\Shared\Application\Bus\Command;

/**
 * Completes the current step of the guided campaign's FlowRun and records its result in the journal.
 * One command serves every kind of step: the server rolls dice, tables and oracles itself, and the
 * fields that belong to the step's kind come from the player. A field that does not belong to the
 * kind, or a missing one, is refused. The caller generates the entry id with JournalEntryIdGenerator.
 */
final readonly class CompleteFlowStep implements Command
{
    /**
     * @param string            $stepKey     the step the player means to complete, refused when the FlowRun is elsewhere
     * @param string|null       $text        prompt step: the answer
     * @param string|null       $optionKey   choice step: the option chosen
     * @param string|null       $likelihood  oracle step: the likelihood level key, only when the step does not fix one
     * @param int|null          $chaosFactor oracle step: null for the oracle's neutral factor (or when it has no chaos)
     * @param list<string>|null $pickedIds   pick step in mode choose: the ids of the candidates chosen, none when nothing matched
     * @param string|null       $characterId character step: the id of the Character of the campaign the player brings; check step: the Character who rolls
     * @param int|null          $difficulty  check step: the Check difficulty, only when the step does not fix one; null for the check's default
     * @param int|null          $situational check step: the Situational modifier, from -100 to 100; null for none
     */
    public function __construct(
        public string $campaignId,
        public string $userId,
        public string $entryId,
        public string $stepKey,
        public ?string $text,
        public ?string $optionKey,
        public ?string $likelihood,
        public ?int $chaosFactor,
        public ?array $pickedIds,
        public ?string $characterId = null,
        public ?int $difficulty = null,
        public ?int $situational = null,
    ) {
    }
}
