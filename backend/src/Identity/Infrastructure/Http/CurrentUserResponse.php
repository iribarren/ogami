<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\UserView;
use App\Identity\Domain\Role;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * JSON body of `GET /api/auth/me` and of a successful login.
 */
#[OA\Schema(required: ['id', 'email', 'roles'])]
final readonly class CurrentUserResponse
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
            items: new OA\Items(ref: new Model(type: Role::class)),
        )]
        public array $roles,
    ) {
    }

    public static function fromView(UserView $view): self
    {
        return new self($view->id, $view->email, $view->roles);
    }
}
