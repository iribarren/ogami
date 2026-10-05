<?php

declare(strict_types=1);

namespace App\Randomness\Infrastructure\Http;

use App\Randomness\Domain\Oracle\LikelihoodOracle;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A likelihood oracle. Documents the contract only.
 */
#[OA\Schema(
    description: 'Rolls 1d<sides> = R against the effective target T: yes when R ≤ T. With p = exceptionalPercent, the yes is exceptional when R ≤ floor(T × p / 100), the no when R > sides − floor((sides − T) × p / 100).',
    required: ['sides', 'levels'],
)]
final readonly class LikelihoodOracleDefinition
{
    /**
     * @param list<LikelihoodLevelDefinition> $levels
     */
    public function __construct(
        #[OA\Property(example: 100, maximum: LikelihoodOracle::MAX_SIDES, minimum: LikelihoodOracle::MIN_SIDES)]
        public int $sides,
        #[OA\Property(
            description: 'The likelihoods a question can be asked with; unique keys.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: LikelihoodLevelDefinition::class)),
            maxItems: LikelihoodOracle::MAX_LEVELS,
            minItems: 1,
        )]
        public array $levels,
        #[OA\Property(ref: new Model(type: LikelihoodChaosDefinition::class), description: 'Absent when the oracle has no chaos factor.')]
        public ?LikelihoodChaosDefinition $chaos = null,
        #[OA\Property(description: 'Size of the exceptional bands, in percent; 0 by default.', example: 20, maximum: LikelihoodOracle::MAX_EXCEPTIONAL_PERCENT, minimum: 0)]
        public ?int $exceptionalPercent = null,
    ) {
    }
}
