<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\Upgrade\CharacterUpgrade;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * What the upgrade changes of one Character: its values and the release Tags it loses.
 */
#[OA\Schema(required: ['id', 'name', 'changes', 'droppedTags'])]
final readonly class UpgradeCharacterResponse
{
    /**
     * @param list<UpgradeFieldChangeResponse> $changes
     * @param list<string>                     $droppedTags
     */
    private function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(example: 'Vi')]
        public string $name,
        #[OA\Property(
            description: 'The changed fields: the new sheet\'s fields in its order, then the removed ones.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: UpgradeFieldChangeResponse::class)),
        )]
        public array $changes,
        #[OA\Property(description: 'The release Tag keys the Character loses because the target release no longer has them.', type: 'array', items: new OA\Items(type: 'string'), example: ['leader'])]
        public array $droppedTags,
    ) {
    }

    public static function fromUpgrade(CharacterUpgrade $upgrade): self
    {
        return new self($upgrade->characterId, $upgrade->name, array_map(UpgradeFieldChangeResponse::fromChange(...), $upgrade->changes), $upgrade->droppedTags);
    }
}
