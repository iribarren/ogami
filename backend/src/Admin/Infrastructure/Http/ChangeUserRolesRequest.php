<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `PUT /api/admin/users/{id}/roles`. Documents the contract only: the controller
 * reads the fields from the request.
 */
#[OA\Schema(required: ['roles'])]
final readonly class ChangeUserRolesRequest
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[OA\Property(
            description: 'The roles that replace the current ones: at least one.',
            type: 'array',
            items: new OA\Items(type: 'string', enum: AdminRoles::VALUES),
        )]
        public array $roles,
    ) {
    }
}
