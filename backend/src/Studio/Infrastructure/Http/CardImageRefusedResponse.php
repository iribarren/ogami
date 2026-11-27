<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Domain\Image\ImageRefused;
use OpenApi\Attributes as OA;

/**
 * JSON body of a refused upload: the usual error message, plus a stable reason code.
 */
#[OA\Schema(required: ['error', 'reason'])]
final readonly class CardImageRefusedResponse
{
    private function __construct(
        #[OA\Property(description: 'A human-readable message stating the limit and the recommended size.')]
        public string $error,
        #[OA\Property(description: 'A stable code for the refusal.', enum: ['missing_file', 'unsupported_format', 'unreadable', 'too_large', 'too_few_pixels', 'too_many_pixels', 'wrong_aspect_ratio'])]
        public string $reason,
    ) {
    }

    public static function fromRefusal(ImageRefused $refusal): self
    {
        return new self($refusal->getMessage(), $refusal->reason->value);
    }
}
