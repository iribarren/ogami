<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\Lists;

use App\Play\Domain\Campaign\Facts\FactId;

/**
 * The ids of the NPCs and Threads a Flow Effect creates, and of the slot facts a fillFact Effect
 * adds. They are name-based UUIDs (RFC 4122 version 5: SHA-1 over a fixed namespace and the name
 * "<campaignId>/<npc|thread|fact>/<count>"), so the domain needs no id generator: the count is the
 * number of items of that kind the campaign already holds, and lists only grow (nothing is
 * deleted), so the id is unique within the campaign and the same for the same campaign, kind and
 * count.
 */
final class GeneratedItemId
{
    private const string NAMESPACE = '8d6c3f5e-2b1a-4c9d-a7e4-5f0b9d1c2e37';

    public static function npc(string $campaignId, int $count): NpcId
    {
        return NpcId::fromString(self::uuid(\sprintf('%s/npc/%d', $campaignId, $count)));
    }

    public static function thread(string $campaignId, int $count): ThreadId
    {
        return ThreadId::fromString(self::uuid(\sprintf('%s/thread/%d', $campaignId, $count)));
    }

    public static function fact(string $campaignId, int $count): FactId
    {
        return FactId::fromString(self::uuid(\sprintf('%s/fact/%d', $campaignId, $count)));
    }

    private static function uuid(string $name): string
    {
        $hex = substr(sha1(hex2bin(str_replace('-', '', self::NAMESPACE)).$name), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex(hexdec($hex[16]) & 0x3 | 0x8);

        return \sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
