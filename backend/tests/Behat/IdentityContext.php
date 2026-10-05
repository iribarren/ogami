<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Identity\Application\CreateUser;
use App\Identity\Application\CreateUserHandler;
use App\Identity\Application\EmailAlreadyInUse;
use App\Identity\Application\GetUser;
use App\Identity\Application\GetUserHandler;
use App\Identity\Application\UnknownRole;
use App\Identity\Domain\Email;
use App\Identity\Domain\UserMustHaveARole;
use App\Tests\Support\Identity\FakePasswordHasher;
use App\Tests\Support\Identity\InMemoryUserRepository;
use App\Tests\Support\Identity\SequentialUserIdGenerator;
use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use PHPUnit\Framework\Assert;

/**
 * User account rules of Identity & Access, on in-memory fakes (no kernel).
 */
final class IdentityContext implements Context
{
    private const string ROLE_LIST = '(?:"(?P<roles>[A-Z_]+(?:" and "[A-Z_]+)*)"|(?P<none>no role))';

    private readonly InMemoryUserRepository $users;
    private readonly SequentialUserIdGenerator $ids;
    private readonly CreateUserHandler $createUser;
    private ?\Throwable $rejection = null;

    public function __construct()
    {
        $this->users = new InMemoryUserRepository();
        $this->ids = new SequentialUserIdGenerator();
        $this->createUser = new CreateUserHandler($this->users, new FakePasswordHasher());
    }

    #[Given('a user exists with email :email')]
    public function aUserExistsWithEmail(string $email): void
    {
        $this->create($email, ['SOLO_PLAYER']);
        Assert::assertNull($this->rejection, $this->rejection?->getMessage() ?? '');
    }

    #[When('/^a user is created with email "(?P<email>[^"]+)" and (?:roles? )?'.self::ROLE_LIST.'$/')]
    public function aUserIsCreated(string $email, string $roles = '', string $none = ''): void
    {
        $this->create($email, '' === $none ? explode('" and "', $roles) : []);
    }

    #[Then('/^the user "(?P<email>[^"]+)" exists with roles? "(?P<roles>[A-Z_]+(?:" and "[A-Z_]+)*)"$/')]
    public function theUserExistsWithRoles(string $email, string $roles): void
    {
        Assert::assertNull($this->rejection, $this->rejection?->getMessage() ?? '');
        $user = $this->users->ofEmail(Email::fromString($email));
        Assert::assertNotNull($user, \sprintf('No user with email "%s".', $email));

        $view = new GetUserHandler($this->users)(new GetUser($user->id()->toString()));
        Assert::assertNotNull($view);
        Assert::assertSame(explode('" and "', $roles), $view->roles);
    }

    #[Then('the user is rejected because the email is already in use')]
    public function rejectedBecauseTheEmailIsInUse(): void
    {
        Assert::assertInstanceOf(EmailAlreadyInUse::class, $this->rejection);
    }

    #[Then('the user is rejected because it has no role')]
    public function rejectedBecauseItHasNoRole(): void
    {
        Assert::assertInstanceOf(UserMustHaveARole::class, $this->rejection);
    }

    #[Then('the user is rejected because the role is unknown')]
    public function rejectedBecauseTheRoleIsUnknown(): void
    {
        Assert::assertInstanceOf(UnknownRole::class, $this->rejection);
    }

    #[Then('/^there (?:is|are) (?P<count>\d+) users?$/')]
    public function thereAreUsers(int $count): void
    {
        Assert::assertSame($count, $this->users->count());
    }

    /**
     * @param list<string> $roles
     */
    private function create(string $email, array $roles): void
    {
        $this->rejection = null;

        try {
            ($this->createUser)(new CreateUser($this->ids->generate()->toString(), $email, 'secret123', $roles));
        } catch (EmailAlreadyInUse|UserMustHaveARole|UnknownRole $rejection) {
            $this->rejection = $rejection;
        }
    }
}
