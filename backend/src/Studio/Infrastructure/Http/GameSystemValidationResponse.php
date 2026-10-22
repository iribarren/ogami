<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\GameSystemValidation;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Whether the draft would be accepted as a release: every error the validator reaches, with its
 * path, in the order publishing meets them. A section that fails hides the errors of the sections
 * that read it until it is fixed.
 */
#[OA\Schema(required: ['valid', 'errors', 'warnings'])]
final readonly class GameSystemValidationResponse
{
    /**
     * @param list<GameSystemValidationErrorResponse> $errors
     * @param list<string>                            $warnings
     */
    private function __construct(
        #[OA\Property]
        public bool $valid,
        #[OA\Property(description: 'Every error when the draft is invalid, the first being the one publishing refuses with; empty when it is valid.', type: 'array', items: new OA\Items(ref: new Model(type: GameSystemValidationErrorResponse::class)))]
        public array $errors,
        #[OA\Property(description: 'Authoring warnings, each starting with the path it is about; empty when the draft is invalid.', type: 'array', items: new OA\Items(type: 'string'), example: ['oracles.tables[0]: has no entries'])]
        public array $warnings,
    ) {
    }

    public static function fromValidation(GameSystemValidation $validation): self
    {
        return new self($validation->valid, array_map(GameSystemValidationErrorResponse::fromError(...), $validation->errors), $validation->warnings);
    }
}
