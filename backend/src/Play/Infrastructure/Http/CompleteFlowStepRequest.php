<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/campaigns/{campaignId}/flow-run/answer`. Documents the contract only:
 * the controller reads the fields from the request.
 *
 * Which of the other fields a step takes depends on its kind: a prompt step a text, a choice step
 * an option, an oracle step a likelihood level (unless the step fixes it) and a chaos factor, a pick
 * step in mode choose the ids it picks, a character step the id of a Character of the campaign, a check step the Character who rolls and, optionally, a difficulty (unless the step fixes one) and a situational modifier, and the check to roll when it offers a check set. Roll and table steps, and a pick step in mode random, take
 * none: the server rolls and draws. A field the kind does not take is refused.
 * Optional fields are nullable with no PHP default: a default makes Nelmio emit `default: null`,
 * which the typed client turns into a required field. `required` lists the required ones.
 */
#[OA\Schema(required: ['stepKey'])]
final readonly class CompleteFlowStepRequest
{
    /**
     * @param list<string>|null          $pickedIds
     * @param list<ShopLineRequest>|null     $buy
     * @param list<ShopSellLineRequest>|null $sell
     */
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
        #[OA\Property(
            description: 'Pick step in mode choose: the ids of the candidates chosen, between the step\'s minimum and maximum, none twice; an empty list when nothing matches. Must be omitted or null in mode random.',
            type: 'array',
            items: new OA\Items(type: 'string'),
            nullable: true,
        )]
        public ?array $pickedIds,
        #[OA\Property(description: 'Character step: the id of a Character of the campaign (check step: the Character who rolls, in the Scene or, outside one, the session party). Choice step: the Character the picked option\'s sheet effects change, required when the option has some (its changesSheet). Prompt step: the Character its sheet effects change, required when the step has some (its changesSheet). Must be omitted or null for the other kinds and for a choice option or prompt without sheet effects.', example: '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9701', nullable: true)]
        public ?string $characterId,
        #[OA\Property(description: 'Check step: the Check difficulty, within the min and max of the check; omitted or null for its default. Must be omitted or null when the step fixes one or the check declares none.', example: 15, nullable: true)]
        public ?int $difficulty,
        #[OA\Property(description: 'Check step: the Situational modifier, from -100 to 100; omitted or null for none.', example: -2, nullable: true)]
        public ?int $situational,
        #[OA\Property(description: 'Check step with a check set (its checks): the key of the check of the set to roll; required there. Must be omitted or null on a single-check step.', example: 'sneak', nullable: true)]
        public ?string $checkKey,
        #[OA\Property(description: 'Shop step: the gear to buy, each gear listed once; omitted, null or empty for none. Must be omitted or null for the other kinds.', type: 'array', items: new OA\Items(ref: new Model(type: ShopLineRequest::class)), nullable: true)]
        public ?array $buy,
        #[OA\Property(description: 'Shop step: the stacks of the Character to sell, applied before the purchases; omitted, null or empty for none. Must be omitted or null for the other kinds.', type: 'array', items: new OA\Items(ref: new Model(type: ShopSellLineRequest::class)), nullable: true)]
        public ?array $sell,
    ) {
    }
}
