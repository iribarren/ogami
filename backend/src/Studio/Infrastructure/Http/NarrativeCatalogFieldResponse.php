<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A number, track or conditions field of the sheet, which a sheet effect may change (a conditions
 * field is marked or cleared).
 *
 * Optional fields are nullable with no PHP default (see GameSystemOracleTableEntry).
 */
#[OA\Schema(required: ['key', 'label'])]
final readonly class NarrativeCatalogFieldResponse
{
    /**
     * @param list<CheckConditionOptionResponse>|null $options
     */
    public function __construct(
        #[OA\Property(example: 'hands')]
        public string $key,
        #[OA\Property(example: 'Hands')]
        public string $label,
        #[OA\Property(description: 'The options a mark or clear effect picks from; only on a conditions field.', type: 'array', items: new OA\Items(ref: new Model(type: CheckConditionOptionResponse::class)), nullable: true)]
        public ?array $options,
    ) {
    }
}
