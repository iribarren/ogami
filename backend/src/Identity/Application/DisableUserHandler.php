<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\CommandHandler;

final readonly class DisableUserHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private UserLoader $loader,
    ) {
    }

    /**
     * @throws UserNotFound
     * @throws OwnAccountProtected when the acting user targets themselves
     */
    public function __invoke(DisableUser $command): void
    {
        $user = $this->loader->load($command->userId);
        if (UserLoader::isSelf($command->actorId, $user)) {
            throw OwnAccountProtected::cannotDisable();
        }

        $user->disable();
        $this->users->save($user);
    }
}
