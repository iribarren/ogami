<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\QueryHandler;

final readonly class ListUsersHandler implements QueryHandler
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    /**
     * @return list<UserView>
     */
    public function __invoke(ListUsers $query): array
    {
        return array_map(UserView::fromUser(...), $this->users->all());
    }
}
