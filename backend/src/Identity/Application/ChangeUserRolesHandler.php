<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Role;
use App\Identity\Domain\UserMustHaveARole;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\CommandHandler;

final readonly class ChangeUserRolesHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private UserLoader $loader,
    ) {
    }

    /**
     * @throws UnknownRole
     * @throws UserNotFound
     * @throws UserMustHaveARole
     * @throws OwnAccountProtected when the acting user would drop their own OWNER role
     */
    public function __invoke(ChangeUserRoles $command): void
    {
        $roles = array_map(
            static fn (string $value): Role => Role::tryFrom($value) ?? throw UnknownRole::named($value),
            $command->roles,
        );
        $user = $this->loader->load($command->userId);

        // An empty list is left to the domain, which refuses it with its own error.
        if ([] !== $roles && UserLoader::isSelf($command->actorId, $user) && !\in_array(Role::Owner, $roles, true)) {
            throw OwnAccountProtected::cannotLoseOwnerRole();
        }

        $user->changeRoles($roles);
        $this->users->save($user);
    }
}
