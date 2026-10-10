<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\FlowRun\FlowStepView;
use App\Play\Domain\GameSystem\Flow\ChoiceStep;
use App\Play\Domain\GameSystem\Flow\ConditionStep;
use App\Play\Domain\GameSystem\Flow\OracleStep;
use App\Play\Domain\GameSystem\Flow\PromptStep;
use App\Play\Domain\GameSystem\Flow\RollStep;
use App\Play\Domain\GameSystem\Flow\TableStep;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The step a guided campaign waits on, flat: the fields of the other kinds are null (the options
 * empty). Effects, bands, branches and the next and skip targets are rules the server applies, so
 * they are not exposed. Placeholders in the title, prompt and tip are not rendered yet.
 */
#[OA\Schema(required: ['key', 'kind', 'title', 'prompt', 'tip', 'mandatory', 'oracle', 'likelihood', 'table', 'dice', 'options'])]
final readonly class FlowStepResponse
{
    /**
     * @param list<FlowStepOptionResponse> $options
     */
    private function __construct(
        #[OA\Property(example: 'slip-past')]
        public string $key,
        #[OA\Property(description: 'How the step is completed; a condition step never waits, so it is never the current one.', example: 'prompt', enum: ['prompt', 'oracle', 'table', 'roll', 'choice'])]
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
        #[OA\Property(description: 'The key of the oracle table rolled (table step); null for the other kinds.', example: 'client', nullable: true)]
        public ?string $table,
        #[OA\Property(description: 'The dice expression rolled (roll step); null for the other kinds.', example: '1d6', nullable: true)]
        public ?string $dice,
        #[OA\Property(
            description: 'The options to pick from (choice step); empty for the other kinds.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: FlowStepOptionResponse::class)),
        )]
        public array $options,
    ) {
    }

    /**
     * @throws \LogicException when the step is a condition step, which never waits
     */
    public static function fromView(FlowStepView $view): self
    {
        $step = $view->step;

        return match (true) {
            $step instanceof PromptStep => self::of($view),
            $step instanceof OracleStep => self::of($view, oracle: $step->oracle, likelihood: $step->likelihood),
            $step instanceof TableStep => self::of($view, table: $step->table),
            $step instanceof RollStep => self::of($view, dice: $step->dice),
            $step instanceof ChoiceStep => self::of($view, options: array_map(FlowStepOptionResponse::of(...), $step->options)),
            $step instanceof ConditionStep => throw new \LogicException(\sprintf('Condition step "%s" never waits: it cannot be the current step.', $step->key)),
            default => throw new \LogicException(\sprintf('No response for step %s.', $step::class)),
        };
    }

    /**
     * @param list<FlowStepOptionResponse> $options
     */
    private static function of(FlowStepView $view, ?string $oracle = null, ?string $likelihood = null, ?string $table = null, ?string $dice = null, array $options = []): self
    {
        $step = $view->step;

        return new self($step->key, $view->kind->value, $step->title, $step->prompt, $step->tip, $step->mandatory, $oracle, $likelihood, $table, $dice, $options);
    }
}
