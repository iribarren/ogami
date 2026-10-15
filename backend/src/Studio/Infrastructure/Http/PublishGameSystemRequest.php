<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/studio/game-systems/{key}/releases`; the body may be left out or `{}`.
 * Documents the contract only: the controller reads the fields from the request.
 *
 * Optional fields are nullable with no PHP default: a default makes Nelmio emit `default: null`,
 * which the typed client turns into a required field.
 */
final readonly class PublishGameSystemRequest
{
    public function __construct(
        #[OA\Property(description: 'Release notes: not blank, at most 2,000 characters; absent or null for none.', example: 'Rebalanced the oracles.', nullable: true)]
        public ?string $notes,
    ) {
    }
}
