<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\OracleView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * An oracle table of a draft as stored, with where the draft uses it.
 */
#[OA\Schema(required: ['definition', 'usedBy'])]
final readonly class OracleTableItemResponse
{
    /**
     * @param array<mixed> $definition
     * @param list<string> $usedBy
     */
    private function __construct(
        #[OA\Property(ref: new Model(type: GameSystemOracleTable::class))]
        public array $definition,
        #[OA\Property(description: 'Where the draft references the table (e.g. "flows[0].phases[1].steps[2]"); a referenced table cannot be renamed or deleted.', type: 'array', items: new OA\Items(type: 'string'), example: ['sceneTypes[1].play[1]'])]
        public array $usedBy,
    ) {
    }

    public static function fromView(OracleView $view): self
    {
        return new self($view->definition, $view->usedBy);
    }
}
