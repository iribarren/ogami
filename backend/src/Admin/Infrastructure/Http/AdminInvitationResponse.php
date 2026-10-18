<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Http;

use App\Identity\Application\InvitationView;
use OpenApi\Attributes as OA;

/**
 * JSON body of a pending invitation in the Admin API. Never carries the link or its token.
 */
#[OA\Schema(required: ['id', 'email', 'roles', 'createdAt', 'expiresAt', 'expired'])]
final readonly class AdminInvitationResponse
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
            description: 'The roles the account is created with.',
            type: 'array',
            items: new OA\Items(type: 'string', enum: AdminRoles::VALUES),
        )]
        public array $roles,
        #[OA\Property(description: 'When the invitation was made (RFC 3339).', format: 'date-time')]
        public string $createdAt,
        #[OA\Property(description: 'Until when the link can be used (RFC 3339).', format: 'date-time')]
        public string $expiresAt,
        #[OA\Property(description: 'The link no longer works; resending renews it.')]
        public bool $expired,
    ) {
    }

    public static function fromView(InvitationView $view): self
    {
        return new self(
            $view->id,
            $view->email,
            $view->roles,
            $view->createdAt->format(\DATE_RFC3339),
            $view->expiresAt->format(\DATE_RFC3339),
            $view->expired,
        );
    }
}
