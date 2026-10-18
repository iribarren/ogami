<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use App\Identity\Application\UserView;
use OpenApi\Attributes as OA;

/**
 * JSON body of a user in the Admin API.
 */
#[OA\Schema(required: ['id', 'email', 'roles', 'status', 'createdAt'])]
final readonly class AdminUserResponse
{
    /**
     * @param list<string> $roles
     */
    private function __construct(
        #[OA\Property(format: 'uuid')]
        public string $id,
        #[OA\Property(format: 'email')]
        public string $email,
        #[OA\Property(
            description: 'Independent roles: none implies another (ADR 0006).',
            type: 'array',
            items: new OA\Items(type: 'string', enum: AdminRoles::VALUES),
        )]
        public array $roles,
        #[OA\Property(description: 'A disabled user cannot sign in.', enum: ['active', 'disabled'])]
        public string $status,
        #[OA\Property(description: 'When the account was created (RFC 3339).', format: 'date-time')]
        public string $createdAt,
    ) {
    }

    public static function fromView(UserView $view): self
    {
        return new self($view->id, $view->email, $view->roles, $view->status, $view->createdAt->format(\DATE_RFC3339));
    }
}
