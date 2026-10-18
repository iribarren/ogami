<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Domain\Email;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserId;
use App\Identity\Infrastructure\Security\IdentityUserChecker;
use App\Identity\Infrastructure\Security\SecurityUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccountStatusException;

#[CoversClass(IdentityUserChecker::class)]
#[CoversClass(SecurityUser::class)]
final class IdentityUserCheckerTest extends TestCase
{
    #[Test]
    public function anActiveUserPasses(): void
    {
        $checker = new IdentityUserChecker();
        $user = SecurityUser::fromUser($this->ada());

        $checker->checkPreAuth($user);
        $checker->checkPostAuth($user);

        self::assertFalse($user->isDisabled());
    }

    #[Test]
    public function aDisabledUserIsRefusedOnlyAfterThePasswordCheck(): void
    {
        $ada = $this->ada();
        $ada->disable();
        $checker = new IdentityUserChecker();
        $user = SecurityUser::fromUser($ada);

        $checker->checkPreAuth($user);

        $this->expectException(AccountStatusException::class);
        $checker->checkPostAuth($user);
    }

    private function ada(): User
    {
        return User::register(UserId::fromString('user-1'), Email::fromString('ada@example.com'), 'hash', [Role::SoloPlayer], new \DateTimeImmutable('2026-10-18T09:00:00+00:00'));
    }
}
