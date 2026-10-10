<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/flow-run/answer`. Documents the contract only:
 * the controller reads the fields from the request.
 *
 * Which of the other fields a step takes depends on its kind: a prompt step a text, a choice step
 * an option, an oracle step a likelihood level (unless the step fixes it) and a chaos factor. Roll
 * and table steps take none: the server rolls. A field the kind does not take is refused.
 * Optional fields are nullable with no PHP default: a default makes Nelmio emit `default: null`,
 * which the typed client turns into a required field. `required` lists the required ones.
 */
#[OA\Schema(required: ['stepKey'])]
final readonly class CompleteFlowStepRequest
{
    public function __construct(
        #[OA\Property(description: 'The step the player means to complete; refused when the FlowRun waits for another.', example: 'intro')]
        public string $stepKey,
        #[OA\Property(description: 'Prompt step: the answer, trimmed; not blank.', example: 'Ada, a fixer', nullable: true)]
        public ?string $text,
        #[OA\Property(description: 'Choice step: the key of the option chosen.', example: 'right', nullable: true)]
        public ?string $optionKey,
        #[OA\Property(description: 'Oracle step: the key of a likelihood level, only when the step does not fix one.', example: 'likely', nullable: true)]
        public ?string $likelihood,
        #[OA\Property(description: 'Oracle step: within the oracle\'s chaos range; omitted or null for its neutral factor. Must be omitted or null when the oracle has no chaos or takes it from a campaign Tracker.', example: 5, nullable: true)]
        public ?int $chaosFactor,
    ) {
    }
}
