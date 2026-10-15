<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\GameSystem;

use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\InvalidGameSystemId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GameSystemId::class)]
#[CoversClass(InvalidGameSystemId::class)]
final class GameSystemIdTest extends TestCase
{
    #[Test]
    public function itKeepsAUuidInLowerCase(): void
    {
        $id = GameSystemId::fromString('0199A1B2-C3D4-7E5F-8A9B-0C1D2E3F4A61');

        self::assertSame('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a61', $id->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notUuids(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['  '];
        yield 'a word' => ['gs-1'];
        yield 'a UUID with a suffix' => ['0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a61x'];
        yield 'a UUID with a trailing newline' => ["0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a61\n"];
        yield 'a UUID without hyphens' => ['0199a1b2c3d47e5f8a9b0c1d2e3f4a61'];
    }

    #[Test]
    #[DataProvider('notUuids')]
    public function itRefusesWhatTheTableWouldRefuse(string $value): void
    {
        $this->expectException(InvalidGameSystemId::class);

        GameSystemId::fromString($value);
    }
}
