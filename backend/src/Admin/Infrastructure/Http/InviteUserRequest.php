<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/admin/invitations`. Documents the contract only: the controller reads
 * the fields from the request.
 */
#[OA\Schema(required: ['email', 'roles'])]
final readonly class InviteUserRequest
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[OA\Property(format: 'email', example: 'ada@example.com')]
        public string $email,
        #[OA\Property(
            description: 'The roles the account is created with: at least one.',
            type: 'array',
            items: new OA\Items(type: 'string', enum: AdminRoles::VALUES),
        )]
        public array $roles,
    ) {
    }
}
