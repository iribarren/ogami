<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\OracleView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A likelihood oracle of a draft as stored, with where the draft uses it.
 */
#[OA\Schema(required: ['definition', 'usedBy'])]
final readonly class LikelihoodOracleItemResponse
{
    /**
     * @param array<mixed> $definition
     * @param list<string> $usedBy
     */
    private function __construct(
        #[OA\Property(ref: new Model(type: GameSystemLikelihoodOracle::class))]
        public array $definition,
        #[OA\Property(description: 'Where the draft references the oracle; a referenced oracle cannot be renamed or deleted.', type: 'array', items: new OA\Items(type: 'string'), example: ['sceneTypes[3].play[0]'])]
        public array $usedBy,
    ) {
    }

    public static function fromView(OracleView $view): self
    {
        return new self($view->definition, $view->usedBy);
    }
}
