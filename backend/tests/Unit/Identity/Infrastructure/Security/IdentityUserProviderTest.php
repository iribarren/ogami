<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Domain\Email;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Infrastructure\Security\IdentityUserProvider;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Tests\Support\Identity\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

#[CoversClass(IdentityUserProvider::class)]
final class IdentityUserProviderTest extends TestCase
{
    private InMemoryUserRepository $users;
    private IdentityUserProvider $provider;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->provider = new IdentityUserProvider($this->users);
    }

    #[Test]
    public function itLoadsAUserByEmailWhateverTheCase(): void
    {
        $this->users->save($this->ada([Role::SoloPlayer]));

        $user = $this->provider->loadUserByIdentifier(' Ada@Example.com ');

        self::assertSame('user-1', $user->id());
        self::assertSame(['ROLE_SOLO_PLAYER'], $user->getRoles());
    }

    #[Test]
    public function anUnknownOrMalformedEmailIsNotFound(): void
    {
        foreach (['nobody@example.com', 'not-an-email'] as $identifier) {
            try {
                $this->provider->loadUserByIdentifier($identifier);
                self::fail('Expected UserNotFoundException for '.$identifier);
            } catch (UserNotFoundException $exception) {
                self::assertSame($identifier, $exception->getUserIdentifier());
            }
        }
    }

    #[Test]
    public function refreshingReloadsTheCurrentRoles(): void
    {
        $session = SecurityUser::fromUser($this->ada([Role::SoloPlayer]));
        $this->users->save($this->ada([Role::SoloPlayer, Role::Owner]));

        self::assertSame(['ROLE_SOLO_PLAYER', 'ROLE_OWNER'], $this->provider->refreshUser($session)->getRoles());
    }

    #[Test]
    public function aVanishedUserCannotBeRefreshed(): void
    {
        $this->expectException(UserNotFoundException::class);

        $this->provider->refreshUser(SecurityUser::fromUser($this->ada([Role::SoloPlayer])));
    }

    #[Test]
    public function itSupportsOnlyTheSecurityUser(): void
    {
        self::assertTrue($this->provider->supportsClass(SecurityUser::class));
        self::assertFalse($this->provider->supportsClass(\stdClass::class));
    }

    /**
     * @param non-empty-list<Role> $roles
     */
    private function ada(array $roles): User
    {
        return User::register(UserId::fromString('user-1'), Email::fromString('ada@example.com'), 'hash', $roles);
    }
}
