<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\GameSystemSheetView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The sheet of a draft: layout, fields as stored and the trackers a formula may read, with the
 * version a client sends back as the expected version of its next sheet write. Every sheet write
 * answers with this.
 */
#[OA\Schema(required: ['version', 'schemaVersion', 'columns', 'groups', 'fields', 'trackers', 'publishedFieldKeys', 'pointBudgets', 'gear', 'currency'])]
final readonly class GameSystemSheetResponse
{
    /**
     * @param list<SheetGroupResponse>|null       $groups
     * @param list<array<array-key, mixed>>       $fields
     * @param list<SheetTrackerResponse>          $trackers
     * @param list<string>                        $publishedFieldKeys
     * @param list<SheetPointBudgetEntryResponse> $pointBudgets
     * @param list<SheetGearEntryResponse>        $gear
     */
    private function __construct(
        #[OA\Property(description: 'The draft\'s version: send it as "expectedVersion" with the next sheet write.', example: 3)]
        public int $version,
        #[OA\Property(description: 'The draft\'s schema version: a version 1 draft has no sheet fields and refuses every command.', example: 2)]
        public int $schemaVersion,
        #[OA\Property(description: 'The sheet\'s columns of groups (1 or 2), 1 when not set.', example: 1)]
        public int $columns,
        #[OA\Property(description: 'The declared groups in order; null when the sheet declares none.', type: 'array', items: new OA\Items(ref: new Model(type: SheetGroupResponse::class)), nullable: true)]
        public ?array $groups,
        #[OA\Property(description: 'The fields as in a release document (the release contract describes each type\'s properties), in order.', type: 'array', items: new OA\Items(type: 'object', example: ['key' => 'pow', 'label' => 'Power', 'type' => 'number', 'min' => 1, 'max' => 99], additionalProperties: true))]
        public array $fields,
        #[OA\Property(description: 'The draft\'s trackers, which a derived field may read as {tracker:key}.', type: 'array', items: new OA\Items(ref: new Model(type: SheetTrackerResponse::class)))]
        public array $trackers,
        #[OA\Property(description: 'The field keys of the GameSystem\'s latest release, empty when it has none: renaming or deleting one makes Characters on later upgrades lose that value.', type: 'array', items: new OA\Items(type: 'string'), example: ['pow', 'hp'])]
        public array $publishedFieldKeys,
        #[OA\Property(description: 'The point budgets of the sheet in order (ADR 0043), a legacy single budget as the key "points"; empty without any.', type: 'array', items: new OA\Items(ref: new Model(type: SheetPointBudgetEntryResponse::class)))]
        public array $pointBudgets,
        #[OA\Property(description: 'The release\'s gear catalog, which inventory fields accept (ADR 0045), in order; empty without any.', type: 'array', items: new OA\Items(ref: new Model(type: SheetGearEntryResponse::class)))]
        public array $gear,
        #[OA\Property(ref: new Model(type: SheetCurrencyResponse::class), description: 'The sheet\'s currency, the number field Characters spend; null without one.', nullable: true)]
        public ?SheetCurrencyResponse $currency,
    ) {
    }

    public static function fromView(GameSystemSheetView $view): self
    {
        return new self(
            $view->version,
            $view->schemaVersion,
            $view->columns,
            null === $view->groups ? null : array_map(SheetGroupResponse::fromView(...), $view->groups),
            $view->fields,
            array_map(SheetTrackerResponse::fromView(...), $view->trackers),
            $view->publishedFieldKeys,
            array_map(SheetPointBudgetEntryResponse::fromView(...), $view->pointBudgets),
            array_map(SheetGearEntryResponse::fromView(...), $view->gear),
            $view->currency instanceof \App\Studio\Application\SheetCurrencyView ? SheetCurrencyResponse::fromView($view->currency) : null,
        );
    }
}
