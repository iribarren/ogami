<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\InvalidUserInput;
use App\Identity\Application\LastActiveOwner;
use App\Identity\Application\UnknownRole;
use App\Identity\Domain\InvalidEmail;
use App\Identity\Domain\InvitationMustHaveARole;
use App\Identity\Domain\UserMustHaveARole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvalidUserInput::class)]
final class InvalidUserInputTest extends TestCase
{
    /**
     * @return iterable<string, array{\Throwable, bool}>
     */
    public static function errors(): iterable
    {
        yield 'malformed email' => [InvalidEmail::malformed('x'), true];
        yield 'user without role' => [UserMustHaveARole::none(), true];
        yield 'invitation without role' => [InvitationMustHaveARole::none(), true];
        yield 'unknown role' => [UnknownRole::named('WIZARD'), true];
        yield 'plain domain exception' => [new \DomainException('a fault'), false];
        yield 'plain invalid argument' => [new \InvalidArgumentException('a fault'), false];
        yield 'last active owner' => [LastActiveOwner::cannotLoseOwnerRole(), false];
        yield 'runtime error' => [new \RuntimeException('a fault'), false];
    }

    #[Test]
    #[DataProvider('errors')]
    public function onlyTheCallersBadInputIsInvalidInput(\Throwable $error, bool $expected): void
    {
        self::assertSame($expected, InvalidUserInput::is($error));
    }
}
