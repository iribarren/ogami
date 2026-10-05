<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\GetUser;
use App\Identity\Application\GetUserHandler;
use App\Identity\Application\UserView;
use App\Identity\Domain\Email;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Tests\Support\Identity\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetUser::class)]
#[CoversClass(GetUserHandler::class)]
#[CoversClass(UserView::class)]
final class GetUserHandlerTest extends TestCase
{
    #[Test]
    public function itReturnsTheUserView(): void
    {
        $users = new InMemoryUserRepository();
        $users->save(User::register(UserId::fromString('user-1'), Email::fromString('ada@example.com'), 'hash', [Role::GameManager, Role::Owner]));

        $view = (new GetUserHandler($users))(new GetUser('user-1'));

        self::assertEquals(new UserView('user-1', 'ada@example.com', ['GAME_MANAGER', 'OWNER']), $view);
    }

    #[Test]
    public function itReturnsNullForAnUnknownUser(): void
    {
        self::assertNull((new GetUserHandler(new InMemoryUserRepository()))(new GetUser('missing')));
    }
}
