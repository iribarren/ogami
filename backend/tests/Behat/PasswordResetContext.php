<?php

declare(strict_types=1);

namespace App\Tests\Behat;

use App\Identity\Application\AccountLinks;
use App\Identity\Application\InvalidAccountLink;
use App\Identity\Application\PasswordResetIdAlreadyInUse;
use App\Identity\Application\PasswordResetIssuer;
use App\Identity\Application\RequestPasswordReset;
use App\Identity\Application\RequestPasswordResetHandler;
use App\Identity\Application\ResetPassword;
use App\Identity\Application\ResetPasswordHandler;
use App\Identity\Application\SendPasswordReset;
use App\Identity\Application\SendPasswordResetHandler;
use App\Identity\Application\UserDisabled;
use App\Identity\Application\UserLoader;
use App\Identity\Application\UserNotFound;
use App\Identity\Application\WeakPassword;
use App\Identity\Domain\Email;
use App\Identity\Domain\InvalidEmail;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Tests\Support\Identity\FakePasswordHasher;
use App\Tests\Support\Identity\FixedClock;
use App\Tests\Support\Identity\InMemoryPasswordResetRepository;
use App\Tests\Support\Identity\InMemoryUserRepository;
use App\Tests\Support\Identity\SequentialPasswordResetIdGenerator;
use App\Tests\Support\Identity\SequentialTokenGenerator;
use App\Tests\Support\Identity\SpyAccountMailer;
use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use PHPUnit\Framework\Assert;

/**
 * Password reset rules of Identity & Access, on in-memory fakes (no kernel). Has its own users and
 * step wording, apart from IdentityContext.
 */
final class PasswordResetContext implements Context
{
    private readonly InMemoryUserRepository $users;
    private readonly InMemoryPasswordResetRepository $resets;
    private readonly SequentialPasswordResetIdGenerator $ids;
    private readonly SpyAccountMailer $mailer;
    private readonly FixedClock $clock;
    private readonly FakePasswordHasher $hasher;
    private readonly RequestPasswordResetHandler $request;
    private readonly SendPasswordResetHandler $send;
    private readonly ResetPasswordHandler $reset;
    private int $userCount = 0;
    private string $lastResetId = '';
    private ?\Throwable $rejection = null;

    public function __construct()
    {
        $this->users = new InMemoryUserRepository();
        $this->resets = new InMemoryPasswordResetRepository();
        $this->ids = new SequentialPasswordResetIdGenerator();
        $this->mailer = new SpyAccountMailer();
        $this->clock = new FixedClock();
        $this->hasher = new FakePasswordHasher();
        $issuer = new PasswordResetIssuer($this->resets, new SequentialTokenGenerator(), new AccountLinks('http://localhost:8080'), $this->mailer, $this->clock);
        $this->request = new RequestPasswordResetHandler($this->users, $issuer);
        $this->send = new SendPasswordResetHandler(new UserLoader($this->users), $issuer);
        $this->reset = new ResetPasswordHandler($this->resets, $this->users, $this->hasher, $this->clock);
    }

    #[Given('the account :email has the password :password')]
    public function theUserHasThePassword(string $email, string $password): void
    {
        $this->users->save(User::register(UserId::fromString(\sprintf('user-%d', ++$this->userCount)), Email::fromString($email), $this->hasher->hash($password), [Role::SoloPlayer], $this->clock->now()));
    }

    #[Given('the account :email is disabled')]
    public function theUserIsDisabled(string $email): void
    {
        $this->theUserHasThePassword($email, 'some password');
        $this->theUserGetsDisabled($email);
    }

    #[Given('the account :email gets disabled')]
    public function theUserGetsDisabled(string $email): void
    {
        $this->userOf($email)->disable();
    }

    #[Given('/^(?P<minutes>\d+) minutes?(?: and (?P<seconds>\d+) seconds?)? pass$/')]
    public function timePasses(int $minutes, int $seconds = 0): void
    {
        $this->clock->moveTo($this->clock->now()->modify(\sprintf('+%d seconds', $minutes * 60 + $seconds))->format(\DATE_ATOM));
    }

    #[When('/^a password reset is requested for "(?P<email>[^"]+)"$/')]
    public function aPasswordResetIsRequested(string $email): void
    {
        $this->run(fn () => ($this->request)(new RequestPasswordReset($this->nextId(), $email)));
    }

    #[When('a password reset is requested again with the same id for :email')]
    public function aPasswordResetIsRequestedAgainWithTheSameId(string $email): void
    {
        $this->run(fn () => ($this->request)(new RequestPasswordReset($this->lastResetId, $email)));
    }

    #[When('the owner sends a password reset to :email')]
    public function theOwnerSendsAPasswordReset(string $email): void
    {
        $this->run(fn () => ($this->send)(new SendPasswordReset($this->nextId(), $this->userOf($email)->id()->toString())));
    }

    #[When('the owner sends a password reset to an unknown user')]
    public function theOwnerSendsAPasswordResetToAnUnknownUser(): void
    {
        $this->run(fn () => ($this->send)(new SendPasswordReset($this->nextId(), 'unknown-user')));
    }

    #[When('/^the link sent to "(?P<email>[^"]+)" is used to choose "(?P<password>[^"]*)"$/')]
    public function theLinkIsUsed(string $email, string $password): void
    {
        $this->resetWith($this->tokenOfLastLinkTo($email), $password);
    }

    #[When('/^the password is reset with the token "(?P<token>[^"]*)" to "(?P<password>[^"]*)"$/')]
    public function thePasswordIsResetWithTheToken(string $token, string $password): void
    {
        $this->resetWith($token, $password);
    }

    #[Then('/^(?:one|(?P<count>\d+)) password reset emails? (?:is|are) sent to "(?P<email>[^"]+)"$/')]
    public function passwordResetEmailsAreSent(?string $count, string $email): void
    {
        Assert::assertSame(array_fill(0, '' === (string) $count ? 1 : (int) $count, $email), array_column($this->mailer->passwordResets, 'email'));
    }

    #[Then('no password reset email is sent')]
    public function noPasswordResetEmailIsSent(): void
    {
        Assert::assertSame([], $this->mailer->passwordResets);
    }

    #[Then('the last password reset email links to :link')]
    public function theLastEmailLinksTo(string $link): void
    {
        Assert::assertSame($link, $this->lastEmail()['link']);
    }

    #[Then('the last password reset email expires at :expiresAt')]
    public function theLastEmailExpiresAt(string $expiresAt): void
    {
        Assert::assertSame($expiresAt, $this->lastEmail()['expiresAt']->format(\DATE_ATOM));
    }

    #[Then('/^there (?:is|are) (?P<count>\d+) stored password resets?$/')]
    public function thereAreStoredPasswordResets(int $count): void
    {
        Assert::assertCount($count, $this->resets->all());
    }

    #[Then('the request is accepted')]
    public function theRequestIsAccepted(): void
    {
        Assert::assertNull($this->rejection, $this->rejection?->getMessage() ?? '');
    }

    #[Then('the request is rejected because the email is invalid')]
    public function rejectedBecauseTheEmailIsInvalid(): void
    {
        Assert::assertInstanceOf(InvalidEmail::class, $this->rejection);
    }

    #[Then('the request is rejected because the user is not found')]
    public function rejectedBecauseTheUserIsNotFound(): void
    {
        Assert::assertInstanceOf(UserNotFound::class, $this->rejection);
    }

    #[Then('the request is rejected because the user is disabled')]
    public function rejectedBecauseTheUserIsDisabled(): void
    {
        Assert::assertInstanceOf(UserDisabled::class, $this->rejection);
    }

    #[Then('the request is rejected because the reset id is in use')]
    public function rejectedBecauseTheResetIdIsInUse(): void
    {
        Assert::assertInstanceOf(PasswordResetIdAlreadyInUse::class, $this->rejection);
    }

    #[Then('the reset link is rejected as invalid')]
    public function theLinkIsRejectedAsInvalid(): void
    {
        Assert::assertInstanceOf(InvalidAccountLink::class, $this->rejection);
    }

    #[Then('the reset is rejected because the password is weak')]
    public function rejectedBecauseThePasswordIsWeak(): void
    {
        Assert::assertInstanceOf(WeakPassword::class, $this->rejection);
    }

    #[Then('the password of :email is :password')]
    public function thePasswordOfIs(string $email, string $password): void
    {
        Assert::assertSame($this->hasher->hash($password), $this->userOf($email)->passwordHash());
    }

    #[Then('the account :email is not left with the password :password')]
    public function theUserIsNotLeftWithThePassword(string $email, string $password): void
    {
        Assert::assertNotSame($this->hasher->hash($password), $this->userOf($email)->passwordHash());
    }

    private function resetWith(string $token, string $password): void
    {
        $this->run(fn () => ($this->reset)(new ResetPassword($token, $password)));
    }

    private function nextId(): string
    {
        return $this->lastResetId = $this->ids->generate()->toString();
    }

    /**
     * Runs a command, keeping its refusal for the Then steps.
     */
    private function run(\Closure $command): void
    {
        $this->rejection = null;

        try {
            $command();
        } catch (InvalidEmail|UserNotFound|UserDisabled|PasswordResetIdAlreadyInUse|InvalidAccountLink|WeakPassword $rejection) {
            $this->rejection = $rejection;
        }
    }

    private function userOf(string $email): User
    {
        $user = $this->users->ofEmail(Email::fromString($email));
        Assert::assertInstanceOf(User::class, $user, \sprintf('No user with email "%s".', $email));

        return $user;
    }

    /**
     * @return array{email: string, link: string, expiresAt: \DateTimeImmutable}
     */
    private function lastEmail(): array
    {
        $last = end($this->mailer->passwordResets);
        Assert::assertIsArray($last, 'No password reset email was sent.');

        return $last;
    }

    private function tokenOfLastLinkTo(string $email): string
    {
        foreach (array_reverse($this->mailer->passwordResets) as $sent) {
            if ($sent['email'] === $email) {
                return substr($sent['link'], (int) strpos($sent['link'], '#token=') + \strlen('#token='));
            }
        }

        throw new \LogicException(\sprintf('No password reset email was sent to "%s".', $email));
    }
}
