<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\GameSystemValidation;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * Whether the draft would be accepted as a release. The validator is fail-fast: it reports the
 * first error with its path.
 */
#[OA\Schema(required: ['valid', 'error', 'warnings'])]
final readonly class GameSystemValidationResponse
{
    /**
     * @param list<string> $warnings
     */
    private function __construct(
        #[OA\Property]
        public bool $valid,
        #[OA\Property(ref: new Model(type: GameSystemValidationErrorResponse::class), description: 'The first error when the draft is invalid; null when it is valid.', nullable: true)]
        public ?GameSystemValidationErrorResponse $error,
        #[OA\Property(description: 'Authoring warnings, each starting with the path it is about; empty when the draft is invalid.', type: 'array', items: new OA\Items(type: 'string'), example: ['oracles.tables[0]: has no entries'])]
        public array $warnings,
    ) {
    }

    public static function fromValidation(GameSystemValidation $validation): self
    {
        return new self($validation->valid, null === $validation->error ? null : GameSystemValidationErrorResponse::withMessage($validation->error), $validation->warnings);
    }
}
