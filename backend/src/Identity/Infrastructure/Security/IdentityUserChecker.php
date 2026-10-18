<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses a disabled user. It refuses after the password check (checkPostAuth),
 * so the response time does not reveal that an account exists and is disabled;
 * the failure handler answers it exactly like bad credentials.
 */
final readonly class IdentityUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof SecurityUser && $user->isDisabled()) {
            throw new DisabledException();
        }
    }
}
