<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/studio/game-systems/{key}/likelihood-oracles` and `PUT …/likelihood-oracles/{oracle}`.
 * Documents the contract only: the controller reads the fields from the request.
 */
#[OA\Schema(required: ['expectedVersion', 'oracle'])]
final readonly class SaveLikelihoodOracleRequest
{
    public function __construct(
        #[OA\Property(description: 'The draft version the client read; another save since then is refused (409).', example: 3)]
        public int $expectedVersion,
        #[OA\Property(ref: new Model(type: GameSystemLikelihoodOracle::class), description: 'The oracle to create, or the whole replacement of the one in the path; its key is the new key on a rename.')]
        public GameSystemLikelihoodOracle $oracle,
    ) {
    }
}
