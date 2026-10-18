<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Identity\Application\AcceptInvitation;
use App\Identity\Application\AcceptInvitationHandler;
use App\Identity\Application\AccountLinks;
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
use App\Identity\Application\InvalidAccountLink;
use App\Identity\Application\InvitationAlreadyPending;
use App\Identity\Application\InvitationLoader;
use App\Identity\Application\InvitationLookupView;
use App\Identity\Application\InvitationNotFound;
use App\Identity\Application\InvitationView;
use App\Identity\Application\InviteUser;
use App\Identity\Application\InviteUserHandler;
use App\Identity\Application\LastActiveOwner;
use App\Identity\Application\ListInvitations;
use App\Identity\Application\ListInvitationsHandler;
use App\Identity\Application\ListUsers;
use App\Identity\Application\ListUsersHandler;
use App\Identity\Application\LookupInvitation;
use App\Identity\Application\LookupInvitationHandler;
use App\Identity\Application\OwnAccountProtected;
use App\Identity\Application\ResendInvitation;
use App\Identity\Application\ResendInvitationHandler;
use App\Identity\Application\RevokeInvitation;
use App\Identity\Application\RevokeInvitationHandler;
use App\Identity\Application\UnknownRole;
use App\Identity\Application\UserLoader;
use App\Identity\Application\UserNotFound;
use App\Identity\Application\UserView;
use App\Identity\Application\WeakPassword;
use App\Identity\Domain\Email;
use App\Identity\Domain\Invitation as InvitationAggregate;
use App\Identity\Domain\InvitationMustHaveARole;
use App\Identity\Domain\InvitationStatus;
use App\Identity\Domain\User;
use App\Identity\Domain\UserMustHaveARole;
use App\Tests\Support\Identity\FakePasswordHasher;
use App\Tests\Support\Identity\FixedClock;
use App\Tests\Support\Identity\InMemoryInvitationRepository;
use App\Tests\Support\Identity\InMemoryUserRepository;
use App\Tests\Support\Identity\SequentialInvitationIdGenerator;
use App\Tests\Support\Identity\SequentialTokenGenerator;
use App\Tests\Support\Identity\SequentialUserIdGenerator;
use App\Tests\Support\Identity\SpyAccountMailer;
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
    private readonly InMemoryInvitationRepository $invitations;
    private readonly SequentialInvitationIdGenerator $invitationIds;
    private readonly SpyAccountMailer $mailer;
    private readonly FixedClock $clock;
    private readonly FakePasswordHasher $hasher;
    private readonly InviteUserHandler $inviteUser;
    private readonly InvitationLoader $invitationLoader;
    private readonly SequentialTokenGenerator $tokens;
    private readonly AccountLinks $links;
    private ?InvitationLookupView $lookup = null;
    private ?\Throwable $rejection = null;

    public function __construct()
    {
        $this->users = new InMemoryUserRepository();
        $this->ids = new SequentialUserIdGenerator();
        $this->clock = new FixedClock();
        $this->hasher = new FakePasswordHasher();
        $this->createUser = new CreateUserHandler($this->users, $this->hasher, $this->clock);
        $this->loader = new UserLoader($this->users);
        $this->invitations = new InMemoryInvitationRepository();
        $this->invitationIds = new SequentialInvitationIdGenerator();
        $this->mailer = new SpyAccountMailer();
        $this->tokens = new SequentialTokenGenerator();
        $this->links = new AccountLinks('http://localhost:8080');
        $this->invitationLoader = new InvitationLoader($this->invitations, $this->clock);
        $this->inviteUser = new InviteUserHandler($this->invitations, $this->users, $this->tokens, $this->links, $this->mailer, $this->clock);
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

    #[Given('/^"(?P<actor>[^"]+)" has invited "(?P<email>[^"]+)" as '.self::ROLE_LIST.'$/')]
    public function hasInvited(string $actor, string $email, string $roles = '', string $none = ''): void
    {
        $this->invite($actor, $email, '' === $none ? explode('" and "', $roles) : []);
        Assert::assertNull($this->rejection, $this->rejection?->getMessage() ?? '');
    }

    #[Given('/^(?P<days>\d+) days pass$/')]
    public function daysPass(int $days): void
    {
        $this->clock->moveTo($this->clock->now()->modify(\sprintf('+%d days', $days))->format(\DATE_ATOM));
    }

    #[When('/^"(?P<actor>[^"]+)" invites "(?P<email>[^"]+)" as '.self::ROLE_LIST.'$/')]
    public function invites(string $actor, string $email, string $roles = '', string $none = ''): void
    {
        $this->invite($actor, $email, '' === $none ? explode('" and "', $roles) : []);
    }

    #[Then('/^(?:one|(?P<count>\d+)) invitation emails? (?:is|are) sent(?: to "(?P<email>[^"]+)")?$/')]
    public function invitationEmailsAreSent(?string $count = null, ?string $email = null): void
    {
        Assert::assertCount(null === $count || '' === $count ? 1 : (int) $count, $this->mailer->invitations);
        if (null !== $email && '' !== $email) {
            Assert::assertSame($email, $this->mailer->invitations[0]['email']);
        }
    }

    #[Then('no invitation email is sent')]
    public function noInvitationEmailIsSent(): void
    {
        Assert::assertSame([], $this->mailer->invitations);
    }

    #[Then('the last invitation email links to :link')]
    public function theLastInvitationEmailLinksTo(string $link): void
    {
        $last = end($this->mailer->invitations);
        Assert::assertIsArray($last);
        Assert::assertSame($link, $last['link']);
    }

    #[Then('/^the pending invitation for "(?P<email>[^"]+)" has roles? "(?P<roles>[A-Z_]+(?:" and "[A-Z_]+)*)"$/')]
    public function thePendingInvitationHasRoles(string $email, string $roles): void
    {
        $pending = $this->invitations->pendingForEmail(Email::fromString($email));
        Assert::assertInstanceOf(InvitationAggregate::class, $pending, \sprintf('No pending invitation for "%s".', $email));
        Assert::assertSame(explode('" and "', $roles), array_map(static fn ($role): string => $role->value, $pending->roles()));
    }

    #[Then('/^there (?:is|are) (?P<pending>\d+) pending and (?P<revoked>\d+) revoked invitations?$/')]
    public function thereArePendingAndRevokedInvitations(int $pending, int $revoked): void
    {
        $statuses = array_map(static fn (InvitationAggregate $invitation): InvitationStatus => $invitation->status(), $this->invitations->all());

        Assert::assertSame(
            [$pending, $revoked],
            [\count(array_filter($statuses, static fn (InvitationStatus $s): bool => InvitationStatus::Pending === $s)), \count(array_filter($statuses, static fn (InvitationStatus $s): bool => InvitationStatus::Revoked === $s))],
        );
    }

    #[Then('the invitation is rejected because the email is already in use')]
    public function theInvitationIsRejectedBecauseTheEmailIsInUse(): void
    {
        Assert::assertInstanceOf(EmailAlreadyInUse::class, $this->rejection);
    }

    #[Then('the invitation is rejected because the email already has a pending invitation')]
    public function theInvitationIsRejectedBecauseItIsPending(): void
    {
        Assert::assertInstanceOf(InvitationAlreadyPending::class, $this->rejection);
    }

    #[Then('the invitation is rejected because the role is unknown')]
    public function theInvitationIsRejectedBecauseTheRoleIsUnknown(): void
    {
        Assert::assertInstanceOf(UnknownRole::class, $this->rejection);
    }

    #[Then('the invitation is rejected because it has no role')]
    public function theInvitationIsRejectedBecauseItHasNoRole(): void
    {
        Assert::assertInstanceOf(InvitationMustHaveARole::class, $this->rejection);
    }

    #[When('/^the invitation of "(?P<email>[^"]+)" is (?P<action>resent|revoked)$/')]
    public function theInvitationIs(string $email, string $action): void
    {
        $this->manageInvitation($this->invitationIdOf($email), $action);
    }

    #[When('/^an unknown invitation is (?P<action>resent|revoked)$/')]
    public function anUnknownInvitationIs(string $action): void
    {
        $this->manageInvitation('unknown-invitation', $action);
    }

    #[When('/^"(?P<email>[^"]+)" accepts the invitation with the password "(?P<password>[^"]*)"$/')]
    public function acceptsWithThePassword(string $email, string $password): void
    {
        $this->accept($this->tokenOfLastLinkTo($email), $password);
    }

    #[When('/^"(?P<email>[^"]+)" accepts the invitation with a password of (?P<length>\d+) characters$/')]
    public function acceptsWithAPasswordOfLength(string $email, int $length): void
    {
        $this->accept($this->tokenOfLastLinkTo($email), str_repeat('a', $length));
    }

    #[When('/^someone accepts with the token "(?P<token>[^"]*)" and a password of (?P<length>\d+) characters$/')]
    public function someoneAcceptsWithTheToken(string $token, int $length): void
    {
        $this->accept($token, str_repeat('a', $length));
    }

    #[When('/^the link of "(?P<email>[^"]+)" is looked up$/')]
    public function theLinkIsLookedUp(string $email): void
    {
        $this->lookUp($this->tokenOfLastLinkTo($email));
    }

    #[When('/^the token "(?P<token>[^"]*)" is looked up$/')]
    public function theTokenIsLookedUp(string $token): void
    {
        $this->lookUp($token);
    }

    #[Then('/^the link is for "(?P<email>[^"]+)" with roles? "(?P<roles>[A-Z_]+(?:" and "[A-Z_]+)*)" until "(?P<expiresAt>[^"]+)"$/')]
    public function theLinkIsFor(string $email, string $roles, string $expiresAt): void
    {
        // After a resend the link is the one of the last email, as the owner would hand it over.
        $view = $this->lookup ?? $this->lookUpLastLinkTo($email);
        Assert::assertSame([$email, explode('" and "', $roles), $expiresAt], [$view->email, $view->roles, $view->expiresAt->format(\DATE_ATOM)]);
    }

    #[Then('the invitation is accepted')]
    public function theInvitationIsAccepted(): void
    {
        Assert::assertNull($this->rejection, $this->rejection?->getMessage() ?? '');
    }

    #[Then('the link is rejected as invalid')]
    public function theLinkIsRejectedAsInvalid(): void
    {
        Assert::assertInstanceOf(InvalidAccountLink::class, $this->rejection);
    }

    #[Then('the invitation is rejected because the password is weak')]
    public function theInvitationIsRejectedBecauseThePasswordIsWeak(): void
    {
        Assert::assertInstanceOf(WeakPassword::class, $this->rejection);
    }

    #[Then('the invitation is rejected because it is not found')]
    public function theInvitationIsRejectedBecauseItIsNotFound(): void
    {
        Assert::assertInstanceOf(InvitationNotFound::class, $this->rejection);
    }

    #[Then('the user :email has the password :password')]
    public function theUserHasThePassword(string $email, string $password): void
    {
        $user = $this->users->ofEmail(Email::fromString($email));
        Assert::assertInstanceOf(User::class, $user);
        Assert::assertSame($this->hasher->hash($password), $user->passwordHash());
    }

    #[Then('no invitations are listed')]
    public function noInvitationsAreListed(): void
    {
        Assert::assertSame([], $this->listedInvitations());
    }

    #[Then('/^the invitations listed are (?P<expected>"[^"]+" as "[^"]+"(?: and "[^"]+" as "[^"]+")*)$/')]
    public function theInvitationsListedAre(string $expected): void
    {
        preg_match_all('/"([^"]+)" as "([^"]+)"/', $expected, $matches, \PREG_SET_ORDER);

        Assert::assertSame(
            array_map(static fn (array $match): array => [$match[1], $match[2]], $matches),
            array_map(static fn (InvitationView $view): array => [$view->email, $view->expired ? 'expired' : 'valid'], $this->listedInvitations()),
        );
    }

    #[Then('/^the invitation of "(?P<email>[^"]+)" is listed as "(?P<state>expired|valid)"$/')]
    public function theInvitationIsListedAs(string $email, string $state): void
    {
        $listed = array_filter($this->listedInvitations(), static fn (InvitationView $view): bool => $view->email === $email);

        Assert::assertSame([$state], array_values(array_map(static fn (InvitationView $view): string => $view->expired ? 'expired' : 'valid', $listed)));
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
     * @return list<InvitationView>
     */
    private function listedInvitations(): array
    {
        return (new ListInvitationsHandler($this->invitations, $this->clock))(new ListInvitations());
    }

    /**
     * The newest invitation for this email, whatever its status.
     */
    private function invitationIdOf(string $email): string
    {
        $found = array_values(array_filter(
            $this->invitations->all(),
            static fn (InvitationAggregate $invitation): bool => $invitation->email()->toString() === $email,
        ));
        Assert::assertNotSame([], $found, \sprintf('No invitation for "%s".', $email));

        return end($found)->id()->toString();
    }

    /**
     * The token in the link of the last invitation email sent to this address.
     */
    private function tokenOfLastLinkTo(string $email): string
    {
        foreach (array_reverse($this->mailer->invitations) as $sent) {
            if ($sent['email'] === $email) {
                return substr($sent['link'], (int) strpos($sent['link'], '#token=') + \strlen('#token='));
            }
        }

        throw new \LogicException(\sprintf('No invitation email was sent to "%s".', $email));
    }

    private function lookUpLastLinkTo(string $email): InvitationLookupView
    {
        return (new LookupInvitationHandler($this->invitationLoader))(new LookupInvitation($this->tokenOfLastLinkTo($email)));
    }

    private function lookUp(string $token): void
    {
        $this->rejection = null;
        $this->lookup = null;

        try {
            $this->lookup = (new LookupInvitationHandler($this->invitationLoader))(new LookupInvitation($token));
        } catch (InvalidAccountLink $rejection) {
            $this->rejection = $rejection;
        }
    }

    private function accept(string $token, string $password): void
    {
        $this->rejection = null;
        $handler = new AcceptInvitationHandler($this->invitations, $this->invitationLoader, $this->users, $this->hasher, $this->clock);

        try {
            $handler(new AcceptInvitation($token, $password, $this->ids->generate()->toString()));
        } catch (InvalidAccountLink|WeakPassword|EmailAlreadyInUse $rejection) {
            $this->rejection = $rejection;
        }
    }

    private function manageInvitation(string $id, string $action): void
    {
        $this->rejection = null;

        try {
            'resent' === $action
                ? (new ResendInvitationHandler($this->invitations, $this->invitationLoader, $this->tokens, $this->links, $this->mailer, $this->clock))(new ResendInvitation($id))
                : (new RevokeInvitationHandler($this->invitations, $this->invitationLoader))(new RevokeInvitation($id));
        } catch (InvitationNotFound $rejection) {
            $this->rejection = $rejection;
        }
    }

    /**
     * @param list<string> $roles
     */
    private function invite(string $actor, string $email, array $roles): void
    {
        $this->rejection = null;

        try {
            ($this->inviteUser)(new InviteUser($this->invitationIds->generate()->toString(), $email, $roles, $this->idOf($actor)));
        } catch (EmailAlreadyInUse|InvitationAlreadyPending|InvitationMustHaveARole|UnknownRole $rejection) {
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
