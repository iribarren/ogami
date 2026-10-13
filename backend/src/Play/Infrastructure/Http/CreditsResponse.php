<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\GameSystem\Credits;
use OpenApi\Attributes as OA;

/**
 * The credits of a GameSystem release (ADR 0021): the notice Play shows, with the license it is
 * provided under.
 */
#[OA\Schema(required: ['notice', 'license', 'url'])]
final readonly class CreditsResponse
{
    private function __construct(
        #[OA\Property(description: 'The text to show, such as a publisher disclaimer.', example: 'Night Job is unofficial content provided under the Homebrew Content Policy of R. Talsorian Games.')]
        public string $notice,
        #[OA\Property(description: 'The name of the license or policy; null when the release names none.', example: 'R. Talsorian Games Homebrew Content Policy', nullable: true)]
        public ?string $license,
        #[OA\Property(description: 'An absolute https:// URL of the license or policy; null when the release gives none.', example: 'https://rtalsoriangames.com/homebrew-content-policy/', nullable: true)]
        public ?string $url,
    ) {
    }

    public static function fromCredits(?Credits $credits): ?self
    {
        return $credits instanceof Credits ? new self($credits->notice, $credits->license, $credits->url) : null;
    }
}
