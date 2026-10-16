<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\GameSystemOraclesView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The oracles of a draft, in order, with the version a client sends back as the expected version
 * of its next oracle write. Every oracle write answers with this.
 */
#[OA\Schema(required: ['version', 'schemaVersion', 'tables', 'likelihood'])]
final readonly class GameSystemOraclesResponse
{
    /**
     * @param list<OracleTableItemResponse>      $tables
     * @param list<LikelihoodOracleItemResponse> $likelihood
     */
    private function __construct(
        #[OA\Property(description: 'The draft\'s version: send it as "expectedVersion" with the next oracle write.', example: 3)]
        public int $version,
        #[OA\Property(description: 'The draft\'s schema version: a version 1 draft refuses the schema version 2 fields.', example: 2)]
        public int $schemaVersion,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: OracleTableItemResponse::class)))]
        public array $tables,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: LikelihoodOracleItemResponse::class)))]
        public array $likelihood,
    ) {
    }

    public static function fromView(GameSystemOraclesView $view): self
    {
        return new self(
            $view->version,
            $view->schemaVersion,
            array_map(OracleTableItemResponse::fromView(...), $view->tables),
            array_map(LikelihoodOracleItemResponse::fromView(...), $view->likelihood),
        );
    }
}
