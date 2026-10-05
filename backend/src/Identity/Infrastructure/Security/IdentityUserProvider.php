<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\Email;
use App\Identity\Domain\InvalidEmail;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads users for Symfony Security from the Identity repository. Each request
 * reloads the signed-in user, so a deleted user loses the session and a role
 * change ends it (Symfony compares the roles of the session and fresh user).
 *
 * @implements UserProviderInterface<SecurityUser>
 */
final readonly class IdentityUserProvider implements UserProviderInterface
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): SecurityUser
    {
        try {
            $user = $this->users->ofEmail(Email::fromString($identifier));
        } catch (InvalidEmail) {
            $user = null;
        }

        return $this->securityUserOrFail($user, $identifier);
    }

    public function refreshUser(UserInterface $user): SecurityUser
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException(\sprintf('Unsupported user class "%s".', $user::class));
        }

        return $this->securityUserOrFail($this->users->ofId(UserId::fromString($user->id())), $user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return SecurityUser::class === $class;
    }

    private function securityUserOrFail(?User $user, string $identifier): SecurityUser
    {
        if (!$user instanceof User) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return SecurityUser::fromUser($user);
    }
}
