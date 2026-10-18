<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * The item rule an import would break.
 */
#[OA\Schema(required: ['path', 'message'])]
final readonly class LibraryImportPreviewErrorResponse
{
    public function __construct(
        #[OA\Property(description: 'The offending part of the draft after the import.', example: 'sceneTypes[0].closing[0].options[0].effects[0]')]
        public string $path,
        #[OA\Property(example: 'tracker "missing" is not a tracker of the draft')]
        public string $message,
    ) {
    }
}
