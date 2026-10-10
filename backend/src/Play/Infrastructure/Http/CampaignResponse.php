<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\CampaignView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * One campaign as its owner plays it: the pinned release, sessions with their scenes, the current
 * session and scene, the oracles of the pinned release, its Trackers with the campaign's values,
 * the Flow played, and the release's Flows and Scene Types.
 */
#[OA\Schema(required: ['id', 'name', 'createdAt', 'pinnedRelease', 'sessions', 'currentSessionNumber', 'currentSceneNumber', 'oracleTables', 'likelihoodOracles', 'trackers', 'flowKey', 'flows', 'sceneTypes'])]
final readonly class CampaignResponse
{
    /**
     * @param list<SessionResponse>          $sessions
     * @param list<OracleTableResponse>      $oracleTables
     * @param list<LikelihoodOracleResponse> $likelihoodOracles
     * @param list<TrackerResponse>          $trackers
     * @param list<FlowSummaryResponse>      $flows
     * @param list<SceneTypeSummaryResponse> $sceneTypes
     */
    private function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'The lost mine')]
        public string $name,
        #[OA\Property(format: 'date-time')]
        public string $createdAt,
        #[OA\Property(ref: new Model(type: PinnedReleaseResponse::class))]
        public PinnedReleaseResponse $pinnedRelease,
        #[OA\Property(
            description: 'In number order.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: SessionResponse::class)),
        )]
        public array $sessions,
        #[OA\Property(description: 'The session under way: the latest one unless it has ended; null before the first one or once it ends.', example: 2, nullable: true)]
        public ?int $currentSessionNumber,
        #[OA\Property(description: 'The latest scene of the current session; null when it has none yet.', example: 1, nullable: true)]
        public ?int $currentSceneNumber,
        #[OA\Property(
            description: 'The oracle tables of the pinned release, in definition order.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: OracleTableResponse::class)),
        )]
        public array $oracleTables,
        #[OA\Property(
            description: 'The likelihood oracles of the pinned release, in definition order.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: LikelihoodOracleResponse::class)),
        )]
        public array $likelihoodOracles,
        #[OA\Property(
            description: 'The Trackers of the pinned release with the campaign\'s values, in definition order; empty for schema version 1.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: TrackerResponse::class)),
        )]
        public array $trackers,
        #[OA\Property(description: 'The key of the Flow the campaign plays; null when played freely.', example: 'heist', nullable: true)]
        public ?string $flowKey,
        #[OA\Property(
            description: 'The Flows of the pinned release, in definition order; empty for schema version 1.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: FlowSummaryResponse::class)),
        )]
        public array $flows,
        #[OA\Property(
            description: 'The Scene Types of the pinned release, in definition order; empty for schema version 1.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: SceneTypeSummaryResponse::class)),
        )]
        public array $sceneTypes,
    ) {
    }

    public static function fromView(CampaignView $view): self
    {
        return new self(
            $view->id,
            $view->name,
            $view->createdAt->format(\DATE_ATOM),
            PinnedReleaseResponse::fromView($view->pinnedRelease),
            array_map(SessionResponse::fromView(...), $view->sessions),
            $view->currentSessionNumber,
            $view->currentSceneNumber,
            array_map(OracleTableResponse::fromView(...), $view->oracleTables),
            array_map(LikelihoodOracleResponse::fromView(...), $view->likelihoodOracles),
            array_map(TrackerResponse::fromView(...), $view->trackers),
            $view->flowKey,
            array_map(FlowSummaryResponse::fromView(...), $view->flows),
            array_map(SceneTypeSummaryResponse::fromView(...), $view->sceneTypes),
        );
    }
}
