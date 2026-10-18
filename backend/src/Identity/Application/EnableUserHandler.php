<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\CommandHandler;

final readonly class EnableUserHandler implements CommandHandler
{
    public function __construct(
        private UserRepository $users,
        private UserLoader $loader,
    ) {
    }

    /**
     * @throws UserNotFound
     */
    public function __invoke(EnableUser $command): void
    {
        $user = $this->loader->load($command->userId);

        $user->enable();
        $this->users->save($user);
    }
}
