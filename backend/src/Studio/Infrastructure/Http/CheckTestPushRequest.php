<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/studio/game-systems/{key}/checks/{check}/test-rolls/push`: the samples,
 * difficulty and situational modifier of the roll being pushed (as sent to the test roll) and the
 * faces that roll showed. Documents the contract only: the controller reads the fields from the
 * request.
 *
 * Every field but `faces` is optional and nullable with no PHP default: a default makes Nelmio emit
 * `default: null`, which the typed client turns into a required field.
 */
#[OA\Schema(required: ['faces'])]
final readonly class CheckTestPushRequest
{
    /**
     * @param list<int> $faces
     */
    public function __construct(
        #[OA\Property(description: 'The face of each die of the roll to push, in roll order: as many as the pool\'s size for these samples and situational modifier, each from 1 to the pool\'s sides (422 otherwise).', type: 'array', items: new OA\Items(type: 'integer'), example: [1, 5, 6, 2])]
        public array $faces,
        #[OA\Property(description: 'The sample value of each number and track field by key, as sent to the test roll.', type: 'object', example: ['hands' => 6], nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'integer'))]
        public ?\stdClass $sheet,
        #[OA\Property(description: 'The sample value of each tracker by key, as sent to the test roll.', type: 'object', example: ['alarm' => 2], nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'integer'))]
        public ?\stdClass $trackers,
        #[OA\Property(description: 'The Check difficulty of the roll, within the check\'s range; absent or null takes the declared default.', example: 13, nullable: true)]
        public ?int $difficulty,
        #[OA\Property(description: 'The situational modifier of the roll, from -100 to 100; absent or null is 0.', example: -2, nullable: true)]
        public ?int $situational,
    ) {
    }
}
