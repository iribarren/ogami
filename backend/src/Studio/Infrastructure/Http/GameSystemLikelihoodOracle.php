<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Randomness\Domain\Oracle\OracleTable;
use App\Randomness\Infrastructure\Http\LikelihoodLevelDefinition;
use App\Randomness\Infrastructure\Http\LikelihoodRandomEventDefinition;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A likelihood oracle of a draft. Documents the contract only; its level and random event shapes
 * are Randomness's (the shared kernel).
 *
 * Optional fields are nullable with no PHP default (see GameSystemOracleTableEntry).
 */
#[OA\Schema(
    description: 'Rolls 1d<sides> = R against the effective target T: yes when R ≤ T. With p = exceptionalPercent, the yes is exceptional when R ≤ floor(T × p / 100), the no when R > sides − floor((sides − T) × p / 100).',
    required: ['key', 'name', 'sides', 'levels'],
)]
final readonly class GameSystemLikelihoodOracle
{
    /**
     * @param list<LikelihoodLevelDefinition> $levels
     */
    public function __construct(
        #[OA\Property(description: 'Unique among the draft\'s tables and likelihood oracles: a-z, 0-9 and "-".', pattern: '^[a-z0-9-]+$', example: 'fate', maxLength: OracleTable::MAX_KEY_LENGTH)]
        public string $key,
        #[OA\Property(example: 'Fate question')]
        public string $name,
        #[OA\Property(example: 100)]
        public int $sides,
        #[OA\Property(description: 'The likelihoods a question can be asked with; unique keys.', type: 'array', items: new OA\Items(ref: new Model(type: LikelihoodLevelDefinition::class)), minItems: 1)]
        public array $levels,
        #[OA\Property(ref: new Model(type: GameSystemLikelihoodChaos::class), description: 'Absent when the oracle has no chaos factor.', nullable: true)]
        public ?GameSystemLikelihoodChaos $chaos,
        #[OA\Property(description: 'Size of the exceptional bands, in percent; 0 by default.', example: 20, minimum: 0)]
        public ?int $exceptionalPercent,
        #[OA\Property(ref: new Model(type: LikelihoodRandomEventDefinition::class), description: 'Absent when a double is no random event (schema version 2).', nullable: true)]
        public ?LikelihoodRandomEventDefinition $randomEvent,
    ) {
    }
}
