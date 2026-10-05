<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\PasswordHasher;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Hashes with the hasher configured for SecurityUser (security.yaml), so login
 * verifies with the same algorithm.
 */
final readonly class SymfonyPasswordHasher implements PasswordHasher
{
    public function __construct(
        private PasswordHasherFactoryInterface $hashers,
    ) {
    }

    public function hash(string $plainPassword): string
    {
        return $this->hashers->getPasswordHasher(SecurityUser::class)->hash($plainPassword);
    }
}
