<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\NarrativeItemView;
use OpenApi\Attributes as OA;

/**
 * A tracker, tag, fact slot, Scene Type or flow of a draft as stored, with where the draft uses it.
 */
#[OA\Schema(required: ['key', 'definition', 'usedBy'])]
final readonly class NarrativeItemResponse
{
    /**
     * @param array<mixed> $definition
     * @param list<string> $usedBy
     */
    private function __construct(
        #[OA\Property(description: 'The item\'s key, the one in the path of a replace or delete.', example: 'alarm')]
        public string $key,
        #[OA\Property(description: 'The item as in a release document (the release contract describes each section\'s fields).', type: 'object', example: ['key' => 'alarm', 'name' => 'Alarm', 'kind' => 'clock', 'segments' => 6], additionalProperties: true)]
        public array $definition,
        #[OA\Property(description: 'Where the draft references the item outside itself (e.g. "flows[0].phases[1].selection"); a referenced tracker, tag, fact slot or Scene Type cannot be renamed or deleted.', type: 'array', items: new OA\Items(type: 'string'), example: ['flows[0].trackers'])]
        public array $usedBy,
    ) {
    }

    public static function fromView(NarrativeItemView $view): self
    {
        return new self($view->key, $view->definition, $view->usedBy);
    }
}
