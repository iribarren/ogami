<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\SceneTypeSummaryView;
use OpenApi\Attributes as OA;

/**
 * A Scene Type of the campaign's pinned release.
 */
#[OA\Schema(required: ['key', 'name', 'purpose', 'tips', 'oracles'])]
final readonly class SceneTypeSummaryResponse
{
    /**
     * @param list<string> $oracles
     */
    private function __construct(
        #[OA\Property(example: 'legwork')]
        public string $key,
        #[OA\Property(example: 'Legwork')]
        public string $name,
        #[OA\Property(example: 'Learn about the target.')]
        public string $purpose,
        #[OA\Property(description: 'Advice for playing the scene; null when the Scene Type has none.', example: 'Ask what the contact wants before you ask for anything.', nullable: true)]
        public ?string $tips,
        #[OA\Property(description: 'The keys of the oracles the Scene Type shortcuts, in shortcut order.', type: 'array', items: new OA\Items(type: 'string'), example: ['fate', 'complications'])]
        public array $oracles,
    ) {
    }

    public static function fromView(SceneTypeSummaryView $view): self
    {
        return new self($view->key, $view->name, $view->purpose, $view->tips, $view->oracles);
    }
}
