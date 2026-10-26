<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\PinnedReleaseView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The GameSystem release a campaign is pinned to (ADR 0014).
 */
#[OA\Schema(required: ['gameSystemKey', 'gameSystemName', 'version', 'theme', 'credits', 'sheet', 'sheetLayout', 'pointBudget', 'pointBudgets'])]
final readonly class PinnedReleaseResponse
{
    /**
     * @param list<SheetFieldResponse>       $sheet
     * @param list<PointBudgetEntryResponse> $pointBudgets
     */
    private function __construct(
        #[OA\Property(example: 'ironsworn')]
        public string $gameSystemKey,
        #[OA\Property(example: 'Ironsworn')]
        public string $gameSystemName,
        #[OA\Property(example: 3)]
        public int $version,
        #[OA\Property(description: 'The curated Theme the release asks for; null means the default app theme.', example: 'noir', nullable: true, enum: ['parchment', 'neon', 'noir', 'eldritch', 'starship'])]
        public ?string $theme,
        #[OA\Property(ref: new Model(type: CreditsResponse::class), description: 'The notice, license and link the release carries; null when it has none.', nullable: true)]
        public ?CreditsResponse $credits,
        #[OA\Property(
            description: 'The fields of the release\'s character sheet in layout order; empty for a release without one. Characters hold a value per field.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: SheetFieldResponse::class)),
        )]
        public array $sheet,
        #[OA\Property(ref: new Model(type: SheetLayoutResponse::class), description: 'How the release lays its sheet out in groups; null when it declares none, and the fields of the same group then render together.', nullable: true)]
        public ?SheetLayoutResponse $sheetLayout,
        #[OA\Property(ref: new Model(type: PointBudgetResponse::class), description: 'The points a player may spend on number fields when creating a Character (ADR 0040): the single budget; null when the sheet has none or several (read pointBudgets). Kept until the UI moves to pointBudgets.', nullable: true)]
        public ?PointBudgetResponse $pointBudget,
        #[OA\Property(description: 'Every point budget of the sheet in order (ADR 0043), a legacy single budget as the key "points"; empty when the sheet has none.', type: 'array', items: new OA\Items(ref: new Model(type: PointBudgetEntryResponse::class)))]
        public array $pointBudgets,
    ) {
    }

    public static function fromView(PinnedReleaseView $view): self
    {
        return new self($view->gameSystemKey, $view->gameSystemName, $view->version, $view->theme, CreditsResponse::fromCredits($view->credits), array_map(SheetFieldResponse::fromView(...), $view->sheet), SheetLayoutResponse::fromLayout($view->sheetLayout), PointBudgetResponse::fromBudget($view->pointBudget), array_map(PointBudgetEntryResponse::fromBudget(...), $view->pointBudgets));
    }
}
