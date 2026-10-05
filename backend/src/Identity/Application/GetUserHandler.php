<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\QueryHandler;

final readonly class GetUserHandler implements QueryHandler
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    public function __invoke(GetUser $query): ?UserView
    {
        $user = $this->users->ofId(UserId::fromString($query->userId));
        if (!$user instanceof User) {
            return null;
        }

        return new UserView(
            $user->id()->toString(),
            $user->email()->toString(),
            array_map(static fn (Role $role): string => $role->value, $user->roles()),
        );
    }
}
