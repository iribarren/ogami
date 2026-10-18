<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\InvitationLoader;
use App\Identity\Application\InvitationNotFound;
use App\Identity\Application\PasswordPolicy;
use App\Identity\Application\WeakPassword;
use App\Tests\Support\Identity\InMemoryInvitationRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordPolicy::class)]
#[CoversClass(WeakPassword::class)]
#[CoversClass(InvitationLoader::class)]
final class PasswordPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function passwords(): iterable
    {
        yield 'empty' => ['', false];
        yield '7 characters' => [str_repeat('a', 7), false];
        yield '8 characters' => [str_repeat('a', 8), true];
        yield '8 characters, 16 bytes' => [str_repeat('é', 8), true];
        yield '4096 characters' => [str_repeat('a', 4096), true];
        yield '4097 characters' => [str_repeat('a', 4097), false];
        yield '4096 characters, 8192 bytes' => [str_repeat('é', 4096), true];
    }

    #[Test]
    #[DataProvider('passwords')]
    public function itCountsCharactersNotBytes(string $password, bool $acceptable): void
    {
        if (!$acceptable) {
            $this->expectException(WeakPassword::class);
        }

        PasswordPolicy::assertAcceptable($password);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aBlankInvitationIdIsNotFoundRatherThanAnInvalidArgument(): void
    {
        $loader = new InvitationLoader(new InMemoryInvitationRepository());

        $this->expectException(InvitationNotFound::class);

        $loader->pending(' ');
    }
}
