<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\FlowRun\FlowStepView;
use App\Play\Domain\Campaign\FlowRun\PendingCheck;
use App\Play\Domain\Campaign\FlowRun\PickedItem;
use App\Play\Domain\Campaign\FlowRun\PickView;
use App\Play\Domain\GameSystem\Flow\CharacterStep;
use App\Play\Domain\GameSystem\Flow\CheckStep;
use App\Play\Domain\GameSystem\Flow\ChoiceStep;
use App\Play\Domain\GameSystem\Flow\ConditionStep;
use App\Play\Domain\GameSystem\Flow\OracleStep;
use App\Play\Domain\GameSystem\Flow\PickStep;
use App\Play\Domain\GameSystem\Flow\PromptStep;
use App\Play\Domain\GameSystem\Flow\RollStep;
use App\Play\Domain\GameSystem\Flow\TableStep;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The step a guided campaign waits on, flat: the fields of the other kinds are null (the options
 * empty). Effects, bands, branches and the next and skip targets are rules the server applies, so
 * they are not exposed. The title, prompt and tip have their placeholders rendered.
 */
#[OA\Schema(required: ['key', 'kind', 'title', 'prompt', 'tip', 'mandatory', 'oracle', 'likelihood', 'table', 'dice', 'options', 'pick', 'fills', 'check', 'checks', 'difficulty', 'difficultyFixed', 'characters', 'inspiration', 'changesSheet', 'pending'])]
final readonly class FlowStepResponse
{
    /**
     * @param list<FlowStepOptionResponse> $options
     * @param list<string>                 $checks
     * @param list<FactSlotResponse>       $fills
     * @param list<ItemRefResponse>        $characters
     * @param list<string>                 $inspiration
     */
    private function __construct(
        #[OA\Property(example: 'slip-past')]
        public string $key,
        #[OA\Property(description: 'How the step is completed; a condition step never waits, so it is never the current one.', example: 'prompt', enum: ['prompt', 'oracle', 'table', 'roll', 'choice', 'pick', 'character', 'check'])]
        public string $kind,
        #[OA\Property(example: 'Who is on the crew?')]
        public string $title,
        #[OA\Property(description: 'The text to read or answer.', example: 'Describe your first crew member.', nullable: true)]
        public ?string $prompt,
        #[OA\Property(description: 'An optional hint.', example: 'Think of a role: a netrunner, a fixer.', nullable: true)]
        public ?string $tip,
        #[OA\Property(description: 'A mandatory step cannot be skipped.')]
        public bool $mandatory,
        #[OA\Property(description: 'The key of the likelihood oracle asked (oracle step); null for the other kinds.', example: 'fate', nullable: true)]
        public ?string $oracle,
        #[OA\Property(description: 'The likelihood level the oracle step always asks at; null when the player picks it, and for the other kinds.', example: 'unlikely', nullable: true)]
        public ?string $likelihood,
        #[OA\Property(description: 'The key of the oracle table the step rolls now (table step): the table mapped to the current Scene Type when the step has a table by Scene Type, else its default (also in a hook or outside a scene); null for the other kinds.', example: 'client', nullable: true)]
        public ?string $table,
        #[OA\Property(description: 'The dice expression rolled (roll step); null for the other kinds.', example: '1d6', nullable: true)]
        public ?string $dice,
        #[OA\Property(
            description: 'The options to pick from (choice step); empty for the other kinds.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: FlowStepOptionResponse::class)),
        )]
        public array $options,
        #[OA\Property(ref: new Model(type: FlowPickStepResponse::class), description: 'What the step picks from (pick step); null for the other kinds.', nullable: true)]
        public ?FlowPickStepResponse $pick,
        #[OA\Property(
            description: 'The Fact slots any Effect of the step may fill, in the release\'s slot order (a fillFact Effect fills a slot once the step is completed); empty for a step that fills none.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: FactSlotResponse::class)),
        )]
        public array $fills,
        #[OA\Property(description: 'The key of the check performed (check step with a single check); null for a check set (see checks) and for the other kinds.', example: 'slip-past', nullable: true)]
        public ?string $check,
        #[OA\Property(
            description: 'The keys of the check set the player picks from, in the order the step lists them; the answer names one as "checkKey" (check step with a check set); empty for a single-check step and for the other kinds.',
            type: 'array',
            items: new OA\Items(type: 'string', example: 'sneak'),
        )]
        public array $checks,
        #[OA\Property(description: 'The Check difficulty the check step fixes, as it stands now; null when the player sets it, for the other kinds, and when the step\'s formula cannot be evaluated (answering the step is then refused).', example: 7, nullable: true)]
        public ?int $difficulty,
        #[OA\Property(description: 'Whether the check step fixes the Check difficulty (a literal, a Tracker or a Formula), even when it cannot be evaluated now (difficulty is then null and answering the step is refused); false when the player sets it and for the other kinds.')]
        public bool $difficultyFixed,
        #[OA\Property(
            description: 'The Characters who can roll the check step: those in the current Scene, outside a Scene those in the session party (check step); empty for the other kinds.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: ItemRefResponse::class)),
        )]
        public array $characters,
        #[OA\Property(
            description: 'The keys of the oracle tables the prompt step offers as inspiration, in order; empty for the other kinds and for a prompt that offers none. The player may roll them; they never change the step.',
            type: 'array',
            items: new OA\Items(type: 'string', example: 'nouns'),
        )]
        public array $inspiration,
        #[OA\Property(description: 'Whether completing the step changes a Character\'s sheet, so the answer must name the Character with "characterId" (prompt step); false for the other kinds. A choice step says it for each option instead.')]
        public bool $changesSheet,
        #[OA\Property(ref: new Model(type: FlowStepPendingResponse::class), description: 'The roll the check step waits on until the player keeps or pushes it (ADR 0041); null while none does, and for the other kinds. While a roll pends, every other FlowRun command is refused.', nullable: true)]
        public ?FlowStepPendingResponse $pending,
    ) {
    }

    /**
     * @throws \LogicException when the step is a condition step, which never waits
     */
    public static function fromView(FlowStepView $view): self
    {
        $step = $view->step;

        return match (true) {
            $step instanceof PromptStep, $step instanceof CharacterStep => self::of($view),
            $step instanceof OracleStep => self::of($view, oracle: $step->oracle, likelihood: $step->likelihood),
            $step instanceof TableStep => self::of($view, table: $view->table ?? $step->table),
            $step instanceof RollStep => self::of($view, dice: $step->dice),
            $step instanceof CheckStep => self::of($view, check: $step->singleCheck(), checks: $step->isSet() ? $step->checks : []),
            $step instanceof ChoiceStep => self::of($view, options: array_map(FlowStepOptionResponse::of(...), $step->options)),
            $step instanceof PickStep => self::of($view, pick: $view->pick instanceof PickView ? FlowPickStepResponse::fromView($view->pick) : throw new \LogicException(\sprintf('Pick step "%s" has no pick view.', $step->key))),
            $step instanceof ConditionStep => throw new \LogicException(\sprintf('Condition step "%s" never waits: it cannot be the current step.', $step->key)),
            default => throw new \LogicException(\sprintf('No response for step %s.', $step::class)),
        };
    }

    /**
     * @param list<FlowStepOptionResponse> $options
     * @param list<string>                 $checks
     */
    private static function of(FlowStepView $view, ?string $oracle = null, ?string $likelihood = null, ?string $table = null, ?string $dice = null, array $options = [], ?FlowPickStepResponse $pick = null, ?string $check = null, array $checks = []): self
    {
        $step = $view->step;
        $checkView = $view->check;

        return new self($step->key, $view->kind->value, $view->title, $view->prompt, $view->tip, $step->mandatory, $oracle, $likelihood, $table, $dice, $options, $pick, array_map(FactSlotResponse::fromSlot(...), $view->fills), $check, $checks, $checkView?->difficulty, $checkView->difficultyFixed ?? false, array_map(static fn (PickedItem $character): ItemRefResponse => ItemRefResponse::of($character->id, $character->name), $checkView->characters ?? []), $step instanceof PromptStep ? $step->inspiration : [], $step instanceof PromptStep && $step->changesSheet(), $view->pending instanceof PendingCheck ? FlowStepPendingResponse::fromPending($view->pending) : null);
    }
}
