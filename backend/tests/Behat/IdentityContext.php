<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Identity\Application\ChangeUserRoles;
use App\Identity\Application\ChangeUserRolesHandler;
use App\Identity\Application\CreateUser;
use App\Identity\Application\CreateUserHandler;
use App\Identity\Application\DisableUser;
use App\Identity\Application\DisableUserHandler;
use App\Identity\Application\EmailAlreadyInUse;
use App\Identity\Application\EnableUser;
use App\Identity\Application\EnableUserHandler;
use App\Identity\Application\GetUser;
use App\Identity\Application\GetUserHandler;
use App\Identity\Application\LastActiveOwner;
use App\Identity\Application\ListUsers;
use App\Identity\Application\ListUsersHandler;
use App\Identity\Application\OwnAccountProtected;
use App\Identity\Application\UnknownRole;
use App\Identity\Application\UserLoader;
use App\Identity\Application\UserNotFound;
use App\Identity\Application\UserView;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Domain\UserMustHaveARole;
use App\Tests\Support\Identity\FakePasswordHasher;
use App\Tests\Support\Identity\FixedClock;
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
    private readonly UserLoader $loader;
    private ?\Throwable $rejection = null;

    public function __construct()
    {
        $this->users = new InMemoryUserRepository();
        $this->ids = new SequentialUserIdGenerator();
        $this->createUser = new CreateUserHandler($this->users, new FakePasswordHasher(), new FixedClock());
        $this->loader = new UserLoader($this->users);
    }

    #[Given('a user exists with email :email')]
    public function aUserExistsWithEmail(string $email): void
    {
        $this->create($email, ['SOLO_PLAYER']);
        Assert::assertNull($this->rejection, $this->rejection?->getMessage() ?? '');
    }

    #[Given('an owner exists with email :email')]
    public function anOwnerExistsWithEmail(string $email): void
    {
        $this->create($email, ['OWNER']);
        Assert::assertNull($this->rejection, $this->rejection?->getMessage() ?? '');
    }

    #[When('/^"(?P<actor>[^"]+)" disables "(?P<email>[^"]+)"$/')]
    public function disables(string $actor, string $email): void
    {
        $this->manage(fn () => (new DisableUserHandler($this->users, $this->loader))(new DisableUser($this->idOf($actor), $this->idOf($email))));
    }

    #[When('/^"[^"]+" enables "(?P<email>[^"]+)"$/')]
    public function enables(string $email): void
    {
        $this->manage(fn () => (new EnableUserHandler($this->users, $this->loader))(new EnableUser($this->idOf($email))));
    }

    #[When('/^"(?P<actor>[^"]+)" (?P<action>disables|enables) an unknown user$/')]
    public function managesAnUnknownUser(string $actor, string $action): void
    {
        $actorId = $this->idOf($actor);
        $this->manage(fn () => 'disables' === $action
            ? (new DisableUserHandler($this->users, $this->loader))(new DisableUser($actorId, 'unknown-user'))
            : (new EnableUserHandler($this->users, $this->loader))(new EnableUser('unknown-user')));
    }

    #[When('/^"(?P<actor>[^"]+)" changes the roles of an unknown user to '.self::ROLE_LIST.'$/')]
    public function changesTheRolesOfAnUnknownUser(string $actor, string $roles = '', string $none = ''): void
    {
        $this->changeRoles($this->idOf($actor), 'unknown-user', '' === $none ? explode('" and "', $roles) : []);
    }

    #[When('/^"(?P<actor>[^"]+)" changes the roles of "(?P<email>[^"]+)" to '.self::ROLE_LIST.'$/')]
    public function changesTheRoles(string $actor, string $email, string $roles = '', string $none = ''): void
    {
        $this->changeRoles($this->idOf($actor), $this->idOf($email), '' === $none ? explode('" and "', $roles) : []);
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

    #[Then('/^the user "(?P<email>[^"]+)" has roles? "(?P<roles>[A-Z_]+(?:" and "[A-Z_]+)*)"$/')]
    public function theUserHasRoles(string $email, string $roles): void
    {
        Assert::assertSame(explode('" and "', $roles), $this->viewOf($email)->roles);
    }

    #[Then('the user :email is :status')]
    public function theUserIs(string $email, string $status): void
    {
        Assert::assertSame($status, $this->viewOf($email)->status);
    }

    #[Then('/^the users listed are "(?P<first>[^"]+)" as "(?P<firstStatus>[^"]+)" and "(?P<second>[^"]+)" as "(?P<secondStatus>[^"]+)"$/')]
    public function theUsersListedAre(string $first, string $firstStatus, string $second, string $secondStatus): void
    {
        $views = (new ListUsersHandler($this->users))(new ListUsers());

        Assert::assertSame(
            [[$first, $firstStatus], [$second, $secondStatus]],
            array_map(static fn (UserView $view): array => [$view->email, $view->status], $views),
        );
    }

    #[Then('the change is rejected because the own account is protected')]
    public function rejectedBecauseTheOwnAccountIsProtected(): void
    {
        Assert::assertInstanceOf(OwnAccountProtected::class, $this->rejection);
    }

    #[Then('the change is rejected because it would leave no active owner')]
    public function rejectedBecauseItWouldLeaveNoActiveOwner(): void
    {
        Assert::assertInstanceOf(LastActiveOwner::class, $this->rejection);
    }

    #[Then('the change is rejected because the user is not found')]
    public function rejectedBecauseTheUserIsNotFound(): void
    {
        Assert::assertInstanceOf(UserNotFound::class, $this->rejection);
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

    private function idOf(string $email): string
    {
        $user = $this->users->ofEmail(Email::fromString($email));
        Assert::assertInstanceOf(User::class, $user, \sprintf('No user with email "%s".', $email));

        return $user->id()->toString();
    }

    private function viewOf(string $email): UserView
    {
        $view = new GetUserHandler($this->users)(new GetUser($this->idOf($email)));
        Assert::assertNotNull($view);

        return $view;
    }

    /**
     * @param list<string> $roles
     */
    private function changeRoles(string $actorId, string $userId, array $roles): void
    {
        $this->manage(fn () => (new ChangeUserRolesHandler($this->users, $this->loader))(new ChangeUserRoles($actorId, $userId, $roles)));
    }

    /**
     * Runs a user management command, keeping the domain refusals for the Then steps.
     */
    private function manage(\Closure $command): void
    {
        $this->rejection = null;

        try {
            $command();
        } catch (OwnAccountProtected|LastActiveOwner|UserNotFound|UserMustHaveARole|UnknownRole $rejection) {
            $this->rejection = $rejection;
        }
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
