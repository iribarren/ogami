<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\Upgrade\DroppedTags;
use OpenApi\Attributes as OA;

/**
 * An NPC or a Thread that loses release Tags in the upgrade.
 */
#[OA\Schema(required: ['id', 'name', 'tags'])]
final readonly class UpgradeDroppedTagsResponse
{
    /**
     * @param list<string> $tags
     */
    private function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'Mira')]
        public string $name,
        #[OA\Property(description: 'The release Tag keys it loses because the target release no longer has them.', type: 'array', items: new OA\Items(type: 'string'), example: ['rival'])]
        public array $tags,
    ) {
    }

    public static function fromDropped(DroppedTags $dropped): self
    {
        return new self($dropped->id, $dropped->name, $dropped->tags);
    }
}
