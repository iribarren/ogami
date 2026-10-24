<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * What a release changed against the release before it (version N-1).
 */
#[OA\Schema(required: ['previousVersion', 'previousSchemaVersion', 'sections'])]
final readonly class ReleaseChangesResponse
{
    /**
     * @param array<string, ReleaseChangesSectionResponse>|null $sections
     */
    private function __construct(
        #[OA\Property(description: 'The version it is compared with: the one before this release.', example: 2)]
        public int $previousVersion,
        #[OA\Property(description: 'The previous release\'s schema version.', example: 2)]
        public int $previousSchemaVersion,
        #[OA\Property(description: 'The changes by section name, as in sectionCounts and outline, every section present. Null when the previous release has another schema version than this one, as their sections differ and are not compared.', type: 'object', nullable: true, additionalProperties: new OA\AdditionalProperties(ref: new Model(type: ReleaseChangesSectionResponse::class)))]
        public ?array $sections,
    ) {
    }

    /**
     * @param array{previousVersion: int, previousSchemaVersion: int, sections: array<string, array{added: list<array{key: string, name: string|null}>, removed: list<array{key: string, name: string|null}>, changed: list<array{key: string, name: string|null, previousName: string|null, fields: list<string>}>, reordered: bool}>|null} $changes
     */
    public static function fromChanges(array $changes): self
    {
        $sections = $changes['sections'];

        return new self(
            $changes['previousVersion'],
            $changes['previousSchemaVersion'],
            null === $sections ? null : array_map(ReleaseChangesSectionResponse::fromSection(...), $sections),
        );
    }
}
