<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\CheckConditionsFieldView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * A conditions sheet field with its options, as the effects of a check's push list them.
 */
#[OA\Schema(required: ['key', 'label', 'options'])]
final readonly class CheckConditionsFieldResponse
{
    /**
     * @param list<CheckConditionOptionResponse> $options
     */
    private function __construct(
        #[OA\Property(description: 'The field\'s key, the "field" of a mark or clear effect.', example: 'conditions')]
        public string $key,
        #[OA\Property(example: 'Conditions')]
        public string $label,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: CheckConditionOptionResponse::class)))]
        public array $options,
    ) {
    }

    public static function fromView(CheckConditionsFieldView $view): self
    {
        return new self($view->key, $view->label, array_map(CheckConditionOptionResponse::fromView(...), $view->options));
    }
}
