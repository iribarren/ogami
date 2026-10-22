<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\GameSystemValidationError;
use OpenApi\Attributes as OA;

/**
 * One error of an invalid draft: where it is and what is wrong.
 */
#[OA\Schema(required: ['path', 'message'])]
final readonly class GameSystemValidationErrorResponse
{
    private function __construct(
        #[OA\Property(description: 'The path of the offending part of the draft: "(root)" for the document itself, otherwise e.g. "flows[0].phases[1].selection.table".', example: 'oracles.likelihood[1].key')]
        public string $path,
        #[OA\Property(description: 'What is wrong with it, without the path.', example: 'must be a lower-case key')]
        public string $message,
    ) {
    }

    public static function fromError(GameSystemValidationError $error): self
    {
        return new self($error->path, $error->message);
    }
}
