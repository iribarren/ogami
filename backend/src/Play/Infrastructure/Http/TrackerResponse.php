<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\TrackerView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A Tracker of the campaign's pinned release with the campaign's value: a counter (min..max) or a
 * clock (0..segments).
 */
#[OA\Schema(required: ['key', 'name', 'kind', 'hint', 'min', 'max', 'segments', 'levels', 'value', 'levelLabel'])]
final readonly class TrackerResponse
{
    /**
     * @param list<TrackerLevelResponse> $levels
     */
    private function __construct(
        #[OA\Property(example: 'heat')]
        public string $key,
        #[OA\Property(example: 'Heat')]
        public string $name,
        #[OA\Property(example: 'counter', enum: ['counter', 'clock'])]
        public string $kind,
        #[OA\Property(example: 'Grows between jobs', nullable: true)]
        public ?string $hint,
        #[OA\Property(description: 'The lowest value; 0 for a clock.', example: -5)]
        public int $min,
        #[OA\Property(description: 'The highest value; the number of segments for a clock.', example: 5)]
        public int $max,
        #[OA\Property(description: 'The number of segments of a clock; null for a counter.', example: null, nullable: true)]
        public ?int $segments,
        #[OA\Property(
            description: 'The named ranges of a counter, in order; empty for a clock.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: TrackerLevelResponse::class)),
        )]
        public array $levels,
        #[OA\Property(description: 'The campaign\'s value, within min..max.', example: 0)]
        public int $value,
        #[OA\Property(description: 'The label of the level the value falls in; null without levels.', example: 'Warm', nullable: true)]
        public ?string $levelLabel,
    ) {
    }

    public static function fromView(TrackerView $view): self
    {
        return new self(
            $view->key,
            $view->name,
            $view->kind,
            $view->hint,
            $view->min,
            $view->max,
            $view->segments,
            array_map(TrackerLevelResponse::fromView(...), $view->levels),
            $view->value,
            $view->levelLabel,
        );
    }
}
