<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\GameSystemNarrativeView;
use App\Studio\Application\NarrativeItemView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The narrative sections of a draft, in order, with the version a client sends back as the expected
 * version of its next narrative write, and the catalog the editors' pickers need. Every narrative
 * write answers with this.
 */
#[OA\Schema(required: ['version', 'schemaVersion', 'trackers', 'tags', 'factSlots', 'sceneTypes', 'flows', 'catalog'])]
final readonly class GameSystemNarrativeResponse
{
    /**
     * @param list<NarrativeItemResponse> $trackers
     * @param list<NarrativeItemResponse> $tags
     * @param list<NarrativeItemResponse> $factSlots
     * @param list<NarrativeItemResponse> $sceneTypes
     * @param list<NarrativeItemResponse> $flows
     */
    private function __construct(
        #[OA\Property(description: 'The draft\'s version: send it as "expectedVersion" with the next narrative write.', example: 3)]
        public int $version,
        #[OA\Property(description: 'The draft\'s schema version: a version 1 draft has no narrative sections and refuses every command.', example: 2)]
        public int $schemaVersion,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: NarrativeItemResponse::class)))]
        public array $trackers,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: NarrativeItemResponse::class)))]
        public array $tags,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: NarrativeItemResponse::class)))]
        public array $factSlots,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: NarrativeItemResponse::class)))]
        public array $sceneTypes,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: NarrativeItemResponse::class)))]
        public array $flows,
        #[OA\Property(ref: new Model(type: NarrativeCatalogResponse::class))]
        public NarrativeCatalogResponse $catalog,
    ) {
    }

    public static function fromView(GameSystemNarrativeView $view): self
    {
        return new self(
            $view->version,
            $view->schemaVersion,
            self::items($view->trackers),
            self::items($view->tags),
            self::items($view->factSlots),
            self::items($view->sceneTypes),
            self::items($view->flows),
            NarrativeCatalogResponse::fromView($view->catalog),
        );
    }

    /**
     * @param list<NarrativeItemView> $items
     *
     * @return list<NarrativeItemResponse>
     */
    private static function items(array $items): array
    {
        return array_map(NarrativeItemResponse::fromView(...), $items);
    }
}
