<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\ReleaseCardImageView;
use App\Play\Application\ReleaseContentView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The release a campaign is pinned to as it was published, for the table: its whole content
 * document and the card images it pinned. Field for field the shape of Studio's release content,
 * so the print and diagram code reads it unchanged (ADR 0054).
 */
#[OA\Schema(required: ['gameSystemKey', 'version', 'publishedAt', 'content', 'cardImages'])]
final readonly class CampaignReleaseResponse
{
    /**
     * @param array<string, mixed>            $content
     * @param list<CampaignCardImageResponse> $cardImages
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
        #[OA\Property(description: 'The images the release pinned, by slot; none for a release published without images. The urls read the bytes whatever the GameSystem publishes later.', type: 'array', items: new OA\Items(ref: new Model(type: CampaignCardImageResponse::class)))]
        public array $cardImages,
    ) {
    }

    public static function fromView(ReleaseContentView $view, string $campaignId): self
    {
        return new self(
            $view->gameSystemKey,
            $view->version,
            $view->publishedAt->format(\DATE_ATOM),
            $view->content,
            array_map(static fn (ReleaseCardImageView $image): CampaignCardImageResponse => CampaignCardImageResponse::fromView($image, $campaignId), $view->cardImages),
        );
    }
}
