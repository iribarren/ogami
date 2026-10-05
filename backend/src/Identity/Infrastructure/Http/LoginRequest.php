<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use OpenApi\Attributes as OA;

/**
 * JSON body of `POST /api/auth/login`. Documents the contract only: the
 * security firewall (`json_login`) reads the credentials.
 */
#[OA\Schema(required: ['email', 'password'])]
final readonly class LoginRequest
{
    public function __construct(
        #[OA\Property(format: 'email', example: 'ada@example.com')]
        public string $email,
        #[OA\Property(format: 'password')]
        public string $password,
    ) {
    }
}
