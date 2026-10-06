<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\OracleTableContent;
use App\Randomness\Domain\Oracle\OracleTableResult;
use App\Randomness\Domain\Oracle\OracleTableSet;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OracleTableContent::class)]
#[CoversClass(InvalidJournalEntryContent::class)]
final class OracleTableContentTest extends TestCase
{
    #[Test]
    public function anOracleTableResultKeepsTheOracleAndEveryStep(): void
    {
        $content = OracleTableContent::fromResult('weather', 'Weather', self::stormResult());

        self::assertSame('oracle-table', $content->kind());
        self::assertSame('weather', $content->oracleKey());
        self::assertSame('Weather', $content->oracleName());
        self::assertSame([
            'kind' => 'oracle-table',
            'oracleKey' => 'weather',
            'oracleName' => 'Weather',
            'steps' => [
                ['tableKey' => 'weather', 'tableName' => 'Weather', 'dice' => '1d6', 'total' => 6, 'text' => 'Storm', 'nestedTableKey' => 'storm-kind'],
                ['tableKey' => 'storm-kind', 'tableName' => 'Storm kind', 'dice' => '1d6', 'total' => 4, 'text' => 'Hail', 'nestedTableKey' => null],
            ],
        ], $content->toArray());
        self::assertSame($content->toArray()['steps'], $content->steps());
    }

    #[Test]
    public function aBlankOracleKeyIsRejected(): void
    {
        $this->expectException(InvalidJournalEntryContent::class);

        OracleTableContent::fromResult(' ', 'Weather', self::stormResult());
    }

    /**
     * Weather rolls 6 (Storm), which nests the weighted storm-kind table, which rolls 4 (Hail).
     */
    public static function stormResult(): OracleTableResult
    {
        return OracleTableSet::fromArray([
            [
                'key' => 'weather',
                'name' => 'Weather',
                'dice' => '1d6',
                'entries' => [
                    ['min' => 1, 'max' => 3, 'text' => 'Clear'],
                    ['min' => 4, 'max' => 5, 'text' => 'Rain'],
                    ['min' => 6, 'max' => 6, 'text' => 'Storm', 'table' => 'storm-kind'],
                ],
            ],
            [
                'key' => 'storm-kind',
                'name' => 'Storm kind',
                'entries' => [
                    ['weight' => 3, 'text' => 'Thunderstorm'],
                    ['text' => 'Hail'],
                    ['weight' => 2, 'text' => 'Blizzard'],
                ],
            ],
        ])->resolve('weather', new ScriptedRandomNumberGenerator(6, 4));
    }
}
