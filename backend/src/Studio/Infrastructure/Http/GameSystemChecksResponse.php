<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\GameSystemChecksView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The checks of a draft in order, what their formulas may read and where each is used, with the
 * version a client sends back as the expected version of its next check write. Every check write
 * answers with this.
 */
#[OA\Schema(required: ['version', 'schemaVersion', 'checks', 'fields', 'trackers', 'conditions', 'usages'])]
final readonly class GameSystemChecksResponse
{
    /**
     * @param list<array<array-key, mixed>>      $checks
     * @param list<CheckFieldResponse>           $fields
     * @param list<CheckTrackerResponse>         $trackers
     * @param list<CheckConditionsFieldResponse> $conditions
     */
    private function __construct(
        #[OA\Property(description: 'The draft\'s version: send it as "expectedVersion" with the next check write.', example: 3)]
        public int $version,
        #[OA\Property(description: 'The draft\'s schema version: a version 1 draft has no checks and refuses every command.', example: 2)]
        public int $schemaVersion,
        #[OA\Property(description: 'The checks as stored, in order (a draft that breaks the rules may hold others than the schema describes).', type: 'array', items: new OA\Items(ref: new Model(type: GameSystemCheck::class)))]
        public array $checks,
        #[OA\Property(description: 'The number, track and derived sheet fields a formula may read.', type: 'array', items: new OA\Items(ref: new Model(type: CheckFieldResponse::class)))]
        public array $fields,
        #[OA\Property(description: 'The draft\'s trackers, which a formula or band bound may read.', type: 'array', items: new OA\Items(ref: new Model(type: CheckTrackerResponse::class)))]
        public array $trackers,
        #[OA\Property(description: 'The draft\'s conditions fields with their options, which the effects of a push cost mark or clear.', type: 'array', items: new OA\Items(ref: new Model(type: CheckConditionsFieldResponse::class)))]
        public array $conditions,
        #[OA\Property(description: 'Where the check steps of the draft name each check, by check key (e.g. "sceneTypes[0].play[0].check"); a used check cannot be renamed or deleted.', type: 'object', example: ['skill' => ['sceneTypes[0].play[0].check']], additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string')))]
        public \stdClass $usages,
    ) {
    }

    public static function fromView(GameSystemChecksView $view): self
    {
        return new self(
            $view->version,
            $view->schemaVersion,
            $view->checks,
            array_map(CheckFieldResponse::fromView(...), $view->fields),
            array_map(CheckTrackerResponse::fromView(...), $view->trackers),
            array_map(CheckConditionsFieldResponse::fromView(...), $view->conditions),
            (object) $view->usages,
        );
    }
}
