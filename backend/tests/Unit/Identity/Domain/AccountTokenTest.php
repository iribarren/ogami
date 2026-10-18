<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\AccountToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AccountToken::class)]
final class AccountTokenTest extends TestCase
{
    #[Test]
    public function itHashesTheValueWithSha256(): void
    {
        $token = AccountToken::fromString('abc');

        self::assertSame('abc', $token->value());
        self::assertSame('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', $token->hash());
    }

    #[Test]
    public function equalValuesGiveEqualHashes(): void
    {
        self::assertSame(AccountToken::fromString('x')->hash(), AccountToken::fromString('x')->hash());
        self::assertNotSame(AccountToken::fromString('x')->hash(), AccountToken::fromString('y')->hash());
    }

    #[Test]
    public function itRefusesABlankValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AccountToken::fromString('');
    }
}
