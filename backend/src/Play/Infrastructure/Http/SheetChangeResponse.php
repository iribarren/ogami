<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Journal\SheetChangeContent;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * One sheet field a sheet Effect changed.
 *
 * @phpstan-import-type SheetChangeData from SheetChangeContent
 */
#[OA\Schema(required: ['field', 'before', 'after', 'dice'])]
final readonly class SheetChangeResponse
{
    private function __construct(
        #[OA\Property(description: 'The key of the sheet field: a number field, or a track field whose current value changed.', example: 'sanity')]
        public string $field,
        #[OA\Property(description: 'The value before the effect.', example: 65)]
        public int $before,
        #[OA\Property(description: 'The value after the effect, clamped to the field.', example: 61)]
        public int $after,
        #[OA\Property(ref: new Model(type: SheetChangeDiceResponse::class), description: 'The dice the effect rolled; null when its value rolled none.', nullable: true)]
        public ?SheetChangeDiceResponse $dice,
    ) {
    }

    /**
     * @param SheetChangeData $change
     */
    public static function of(array $change): self
    {
        $dice = $change['dice'];

        return new self($change['field'], $change['before'], $change['after'], null === $dice ? null : new SheetChangeDiceResponse($dice['expression'], $dice['total']));
    }
}
