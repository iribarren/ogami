<?php

declare(strict_types=1);

namespace App\Studio\Infrastructure\Http;

use App\Studio\Application\LibraryImportsView;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * What a draft imported from the library, in the order imported, with the version a client sends
 * back as the expected version of its next write. Every import command answers with this.
 */
#[OA\Schema(required: ['version', 'imports'])]
final readonly class LibraryImportsResponse
{
    /**
     * @param list<LibraryImportResponse> $imports
     */
    private function __construct(
        #[OA\Property(description: 'The draft\'s version: send it as "expectedVersion" with the next write.', example: 3)]
        public int $version,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: LibraryImportResponse::class)))]
        public array $imports,
    ) {
    }

    public static function fromView(LibraryImportsView $view): self
    {
        return new self($view->version, array_map(LibraryImportResponse::fromView(...), $view->imports));
    }
}
