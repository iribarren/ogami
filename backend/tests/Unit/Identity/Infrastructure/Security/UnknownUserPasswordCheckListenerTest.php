<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Domain\Email;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Infrastructure\Security\IdentityUserProvider;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\UnknownUserPasswordCheckListener;
use App\Tests\Support\Identity\InMemoryUserRepository;
use App\Tests\Support\Identity\SpyPasswordHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

#[CoversClass(UnknownUserPasswordCheckListener::class)]
final class UnknownUserPasswordCheckListenerTest extends TestCase
{
    private SpyPasswordHasher $hasher;
    private InMemoryUserRepository $users;
    private UnknownUserPasswordCheckListener $listener;

    protected function setUp(): void
    {
        $this->hasher = new SpyPasswordHasher();
        $this->users = new InMemoryUserRepository();
        $this->listener = new UnknownUserPasswordCheckListener(
            new PasswordHasherFactory([SecurityUser::class => $this->hasher]),
            new ArrayAdapter(),
        );
    }

    #[Test]
    public function anUnknownEmailStillCostsAPasswordVerification(): void
    {
        try {
            ($this->listener)($this->checkPassport('nobody@example.com', 'secret123'));
            self::fail('Expected the unknown user to stay unknown.');
        } catch (UserNotFoundException) {
        }

        self::assertSame([['hashed:dummy', 'secret123']], $this->hasher->verified);
    }

    #[Test]
    public function aMalformedEmailStillCostsAPasswordVerification(): void
    {
        try {
            ($this->listener)($this->checkPassport('not-an-email', 'secret123'));
            self::fail('Expected the malformed email to be unknown.');
        } catch (UserNotFoundException) {
        }

        self::assertCount(1, $this->hasher->verified);
    }

    #[Test]
    public function theDummyHashIsHashedOnceAndReused(): void
    {
        foreach (['nobody@example.com', 'someone@example.com'] as $email) {
            try {
                ($this->listener)($this->checkPassport($email, 'secret123'));
            } catch (UserNotFoundException) {
            }
        }

        self::assertSame(1, $this->hasher->hashed);
        self::assertCount(2, $this->hasher->verified);
    }

    #[Test]
    public function aKnownUserIsLeftToTheRegularCredentialsCheck(): void
    {
        $this->users->save(User::register(UserId::fromString('user-1'), Email::fromString('ada@example.com'), 'hash', [Role::SoloPlayer]));

        ($this->listener)($this->checkPassport('ada@example.com', 'secret123'));

        self::assertSame([], $this->hasher->verified);
    }

    private function checkPassport(string $email, string $password): CheckPassportEvent
    {
        $provider = new IdentityUserProvider($this->users);

        return new CheckPassportEvent(
            self::createStub(AuthenticatorInterface::class),
            new Passport(new UserBadge($email, $provider->loadUserByIdentifier(...)), new PasswordCredentials($password)),
        );
    }
}
