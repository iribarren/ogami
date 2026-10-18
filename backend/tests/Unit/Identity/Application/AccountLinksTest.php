<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\AccountLinks;
use App\Identity\Domain\AccountToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AccountLinks::class)]
final class AccountLinksTest extends TestCase
{
    #[Test]
    public function theTokenTravelsInTheFragmentNeverTheQueryString(): void
    {
        $links = new AccountLinks('https://ogami.example/');
        $token = AccountToken::fromString('tok-_123');

        self::assertSame('https://ogami.example/accept-invitation#token=tok-_123', $links->invitation($token));
        self::assertSame('https://ogami.example/reset-password#token=tok-_123', $links->passwordReset($token));
    }
}
