<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\Lists;

use App\Play\Domain\Campaign\Lists\GeneratedItemId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GeneratedItemId::class)]
final class GeneratedItemIdTest extends TestCase
{
    #[Test]
    public function itIsAVersion5Uuid(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', GeneratedItemId::npc('campaign-1', 0)->toString());
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', GeneratedItemId::thread('campaign-1', 499)->toString());
    }

    #[Test]
    public function itIsTheSameForTheSameCampaignKindAndCount(): void
    {
        self::assertTrue(GeneratedItemId::npc('campaign-1', 3)->equals(GeneratedItemId::npc('campaign-1', 3)));
        self::assertTrue(GeneratedItemId::thread('campaign-1', 3)->equals(GeneratedItemId::thread('campaign-1', 3)));
    }

    #[Test]
    public function itDiffersPerCampaignKindAndCount(): void
    {
        $ids = [
            GeneratedItemId::npc('campaign-1', 0)->toString(),
            GeneratedItemId::npc('campaign-1', 1)->toString(),
            GeneratedItemId::npc('campaign-2', 0)->toString(),
            GeneratedItemId::thread('campaign-1', 0)->toString(),
        ];

        self::assertCount(4, array_unique($ids));
    }

    #[Test]
    public function itFollowsRfc4122ForAKnownName(): void
    {
        // uuid5(8d6c3f5e-2b1a-4c9d-a7e4-5f0b9d1c2e37, "campaign-1/npc/0"), derived again in the test with pack() and masks.
        self::assertSame($this->expected('campaign-1/npc/0'), GeneratedItemId::npc('campaign-1', 0)->toString());
    }

    private function expected(string $name): string
    {
        $hash = sha1(pack('H*', '8d6c3f5e2b1a4c9da7e45f0b9d1c2e37').$name);
        $time = (hexdec(substr($hash, 12, 4)) & 0x0FFF) | 0x5000;
        $clock = (hexdec(substr($hash, 16, 4)) & 0x3FFF) | 0x8000;

        return \sprintf('%s-%s-%04x-%04x-%s', substr($hash, 0, 8), substr($hash, 8, 4), $time, $clock, substr($hash, 20, 12));
    }
}
