<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\CheckConditionOptionView;
use App\Studio\Application\NarrativeCatalogView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * What the other sections of a draft offer the narrative editors as pickers.
 */
#[OA\Schema(required: ['tables', 'likelihood', 'checks', 'fields'])]
final readonly class NarrativeCatalogResponse
{
    /**
     * @param list<NarrativeCatalogTableResponse>      $tables
     * @param list<NarrativeCatalogLikelihoodResponse> $likelihood
     * @param list<NarrativeCatalogCheckResponse>      $checks
     * @param list<NarrativeCatalogFieldResponse>      $fields
     */
    private function __construct(
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: NarrativeCatalogTableResponse::class)))]
        public array $tables,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: NarrativeCatalogLikelihoodResponse::class)))]
        public array $likelihood,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: NarrativeCatalogCheckResponse::class)))]
        public array $checks,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: NarrativeCatalogFieldResponse::class)))]
        public array $fields,
    ) {
    }

    public static function fromView(NarrativeCatalogView $view): self
    {
        return new self(
            array_map(static fn (array $table): NarrativeCatalogTableResponse => new NarrativeCatalogTableResponse($table['key'], $table['name'], $table['entries']), $view->tables),
            array_map(static fn (array $oracle): NarrativeCatalogLikelihoodResponse => new NarrativeCatalogLikelihoodResponse($oracle['key'], $oracle['name'], $oracle['levels']), $view->likelihood),
            array_map(static fn (array $check): NarrativeCatalogCheckResponse => new NarrativeCatalogCheckResponse(
                $check['key'],
                $check['label'],
                $check['bands'],
                null === $check['difficulty'] ? null : new NarrativeCatalogDifficultyResponse($check['difficulty']['default'], $check['difficulty']['min'], $check['difficulty']['max']),
            ), $view->checks),
            array_map(static fn (array $field): NarrativeCatalogFieldResponse => new NarrativeCatalogFieldResponse($field['key'], $field['label'], null === $field['options'] ? null : array_map(static fn (array $option): CheckConditionOptionResponse => CheckConditionOptionResponse::fromView(new CheckConditionOptionView($option['key'], $option['label'])), $field['options'])), $view->fields),
        );
    }
}
