<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Domain\GameSystem\CheckInUse;
use App\Studio\Domain\GameSystem\NarrativeItemInUse;
use App\Studio\Domain\GameSystem\OracleInUse;
use App\Studio\Domain\GameSystem\SheetFieldInUse;
use OpenApi\Attributes as OA;

/**
 * An oracle, an entry key of one, a tracker, tag, fact slot or Scene Type, a sheet field, a check or a band of one is
 * referenced by the draft, so it cannot be renamed, removed or deleted.
 */
#[OA\Schema(required: ['error', 'paths'])]
final readonly class OracleInUseResponse
{
    /**
     * @param list<string> $paths
     */
    private function __construct(
        #[OA\Property(description: 'A human-readable message naming where it is used.')]
        public string $error,
        #[OA\Property(description: 'Where the draft references it (e.g. "flows[0].phases[1].steps[2]").', type: 'array', items: new OA\Items(type: 'string'), example: ['sceneTypes[1].play[1]'])]
        public array $paths,
    ) {
    }

    public static function fromException(OracleInUse|NarrativeItemInUse|SheetFieldInUse|CheckInUse $exception): self
    {
        return new self($exception->getMessage(), $exception->paths());
    }
}
