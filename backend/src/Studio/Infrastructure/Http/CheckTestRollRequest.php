<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/studio/game-systems/{key}/checks/{check}/test-rolls`. Documents the
 * contract only: the controller reads the fields from the request.
 *
 * Every field is optional and nullable with no PHP default: a default makes Nelmio emit
 * `default: null`, which the typed client turns into a required field.
 */
#[OA\Schema]
final readonly class CheckTestRollRequest
{
    public function __construct(
        #[OA\Property(description: 'The sample value of each number and track field by key: an integer within the number\'s min..max, or a track\'s 0..max. A missing number takes its default (else 0), a missing track 0. A derived field is computed, so it takes none; an unknown key is refused (422).', type: 'object', example: ['hands' => 6], nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'integer'))]
        public ?\stdClass $sheet,
        #[OA\Property(description: 'The sample value of each tracker by key: an integer within a counter\'s min..max or a clock\'s 0..segments. A missing tracker takes its initial value.', type: 'object', example: ['alarm' => 2], nullable: true, additionalProperties: new OA\AdditionalProperties(type: 'integer'))]
        public ?\stdClass $trackers,
        #[OA\Property(description: 'The Check difficulty, within the check\'s range; absent or null takes the declared default. Refused (422) for a check that declares none.', example: 13, nullable: true)]
        public ?int $difficulty,
        #[OA\Property(description: 'The situational modifier, from -100 to 100; absent or null is 0.', example: -2, nullable: true)]
        public ?int $situational,
    ) {
    }
}
