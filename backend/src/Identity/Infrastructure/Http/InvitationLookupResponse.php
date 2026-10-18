<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\InvitationLookupView;
use App\Identity\Domain\Role;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/auth/invitations/lookup`: what the invited person sees before choosing a password.
 */
#[OA\Schema(required: ['email', 'roles', 'expiresAt'])]
final readonly class InvitationLookupResponse
{
    /**
     * @param list<string> $roles
     */
    private function __construct(
        #[OA\Property(format: 'email')]
        public string $email,
        #[OA\Property(
            description: 'The roles the account is created with.',
            type: 'array',
            items: new OA\Items(ref: new Model(type: Role::class)),
        )]
        public array $roles,
        #[OA\Property(description: 'Until when the link can be used (RFC 3339).', format: 'date-time')]
        public string $expiresAt,
    ) {
    }

    public static function fromView(InvitationLookupView $view): self
    {
        return new self($view->email, $view->roles, $view->expiresAt->format(\DATE_RFC3339));
    }
}
