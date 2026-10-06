<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\LikelihoodChaosView;
use App\Play\Application\LikelihoodOracleView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A likelihood oracle of the campaign's pinned release: what the player picks to ask it.
 */
#[OA\Schema(required: ['key', 'name', 'levels', 'chaos'])]
final readonly class LikelihoodOracleResponse
{
    /**
     * @param list<LikelihoodLevelResponse> $levels
     */
    private function __construct(
        #[OA\Property(example: 'fate')]
        public string $key,
        #[OA\Property(example: 'Fate question')]
        public string $name,
        #[OA\Property(
            description: 'In definition order.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: LikelihoodLevelResponse::class)),
        )]
        public array $levels,
        #[OA\Property(ref: new Model(type: LikelihoodChaosResponse::class), description: 'Null when the oracle takes no chaos factor.', nullable: true)]
        public ?LikelihoodChaosResponse $chaos,
    ) {
    }

    public static function fromView(LikelihoodOracleView $view): self
    {
        return new self(
            $view->key,
            $view->name,
            array_map(LikelihoodLevelResponse::fromView(...), $view->levels),
            $view->chaos instanceof LikelihoodChaosView ? LikelihoodChaosResponse::fromView($view->chaos) : null,
        );
    }
}
