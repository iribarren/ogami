<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\GameSystemReleaseContentView;
use App\Studio\Domain\Image\CardImagePin;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * One release as it was published, for printing: its whole content document and the card images it
 * pinned.
 */
#[OA\Schema(required: ['gameSystemKey', 'version', 'publishedAt', 'content', 'cardImages'])]
final readonly class GameSystemReleaseContentResponse
{
    /**
     * @param array<string, mixed>    $content
     * @param list<CardImageResponse> $cardImages
     */
    private function __construct(
        #[OA\Property(example: 'ironsworn')]
        public string $gameSystemKey,
        #[OA\Property(example: 3)]
        public int $version,
        #[OA\Property(format: 'date-time')]
        public string $publishedAt,
        #[OA\Property(description: 'The release content as published, a JSON object of the release contract (docs/contracts/gamesystem-release.md) at the release\'s schema version.', type: 'object', additionalProperties: true)]
        public array $content,
        #[OA\Property(description: 'The images the release pinned, by slot; none for a release published without images. The urls read the bytes whoever replaces the draft\'s images later.', type: 'array', items: new OA\Items(ref: new Model(type: CardImageResponse::class)))]
        public array $cardImages,
    ) {
    }

    public static function fromView(GameSystemReleaseContentView $view): self
    {
        return new self(
            $view->gameSystemKey,
            $view->version,
            $view->publishedAt->format(\DATE_ATOM),
            $view->content,
            array_map(static fn (CardImagePin $pin): CardImageResponse => CardImageResponse::fromPin($pin, $view->gameSystemKey), $view->cardImages),
        );
    }
}
