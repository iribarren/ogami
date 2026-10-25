<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Formula\Domain\Formula;
use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\Check\CheckBand;
use App\Play\Domain\GameSystem\Check\CheckPool;
use App\Play\Domain\GameSystem\Check\ReferenceLabels;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\Journal\CheckContent;
use App\Play\Domain\Journal\CheckPoolContent;
use App\Play\Domain\Journal\InvalidJournalEntryContent;
use App\Play\Domain\Journal\JournalEntryContents;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckPoolContent::class)]
#[CoversClass(CheckContent::class)]
final class CheckPoolContentTest extends TestCase
{
    /**
     * Vi (agility 2, stealth 1, physical 1) rolls the pool "sneak": {agility} + {stealth} - {physical}
     * d6 (2 dice), succeeding on 5 or more, with one extra die; the dice show 6, 2 and 5.
     */
    public static function sneak(): CheckContent
    {
        $check = new Check('sneak', 'Sneak', 'Quietly', null, null, null, [new CheckBand('miss', 'Miss', 0), new CheckBand('hit', 'Hit')], new CheckPool(Formula::fromString('{agility} + {stealth} - {physical}'), 6, 5));
        $template = new SheetTemplate([new NumberField('agility', 'Agility'), new NumberField('stealth', 'Stealth'), new NumberField('physical', 'Physical')]);
        $result = $check->perform(new ScriptedRandomNumberGenerator(6, 2, 5), SheetValues::of($template, ['agility' => 2, 'stealth' => 1, 'physical' => 1], []), null, 1);

        return CheckContent::fromResult('char-1', 'Vi', $check, $result, new ReferenceLabels($template));
    }

    #[Test]
    public function aPoolCheckKeepsTheCountTheSizeTheDiceAndTheSuccesses(): void
    {
        $content = self::sneak();

        self::assertSame([
            'kind' => 'check',
            'characterId' => 'char-1',
            'characterName' => 'Vi',
            'checkKey' => 'sneak',
            'checkLabel' => 'Sneak',
            'expression' => '3d6',
            'groups' => [],
            'diceTotal' => 2,
            'modifier' => 0,
            'situational' => 1,
            'difficulty' => null,
            'total' => 2,
            'bandKey' => 'hit',
            'bandLabel' => 'Hit',
            'hint' => 'Quietly',
            'modifierFormula' => null,
            'modifierTerms' => [],
            'pool' => [
                'count' => 2,
                'countFormula' => '{agility} + {stealth} - {physical}',
                'terms' => [
                    ['reference' => '{agility}', 'label' => 'Agility', 'value' => 2],
                    ['reference' => '{stealth}', 'label' => 'Stealth', 'value' => 1],
                    ['reference' => '{physical}', 'label' => 'Physical', 'value' => 1],
                ],
                'size' => 3,
                'sides' => 6,
                'successAt' => 5,
                'dice' => [
                    ['group' => 'pool', 'face' => 6, 'success' => true],
                    ['group' => 'pool', 'face' => 2, 'success' => false],
                    ['group' => 'pool', 'face' => 5, 'success' => true],
                ],
                'successes' => 2,
            ],
        ], $content->toArray());
        self::assertInstanceOf(CheckPoolContent::class, $content->pool());
    }

    #[Test]
    public function aPoolCheckRoundTripsThroughItsArray(): void
    {
        $content = self::sneak();

        self::assertEquals($content, JournalEntryContents::fromArray($content->toArray()));
    }

    #[Test]
    public function aNumberCountStoresNoFormulaAndNoTerms(): void
    {
        $check = new Check('sneak', 'Sneak', null, null, null, null, [new CheckBand('only', 'Only')], new CheckPool(2, 10, 8));
        $template = new SheetTemplate([]);
        $result = $check->perform(new ScriptedRandomNumberGenerator(8, 7), SheetValues::of($template, [], []), null, 0);

        $content = CheckContent::fromResult('char-1', 'Vi', $check, $result, new ReferenceLabels($template));

        $pool = $content->pool();
        self::assertInstanceOf(CheckPoolContent::class, $pool);
        self::assertSame([2, null, [], 1], [$pool->count, $pool->countFormula, $pool->terms, $content->total()]);
        self::assertEquals($content, JournalEntryContents::fromArray($content->toArray()));
    }

    /**
     * Entries of dice checks, and those stored before pools, have no pool part and read exactly as before.
     */
    #[Test]
    public function aDiceCheckHasNoPoolPartAndStoresTheSameArrayAsBefore(): void
    {
        $content = CheckContentTest::edgeCheck();
        $stored = $content->toArray();

        self::assertArrayNotHasKey('pool', $stored);
        self::assertNull($content->pool());
        $read = JournalEntryContents::fromArray($stored);
        self::assertInstanceOf(CheckContent::class, $read);
        self::assertNull($read->pool());
        self::assertSame($stored, $read->toArray());
        self::assertEquals($content, JournalEntryContents::fromArray($stored + ['pool' => null]));
    }

    /**
     * @param array<mixed> $data
     */
    #[Test]
    #[DataProvider('malformedPools')]
    public function aMalformedPoolPartIsRejected(array $data, string $message): void
    {
        $this->expectException(InvalidJournalEntryContent::class);
        $this->expectExceptionMessageIsOrContains($message);

        JournalEntryContents::fromArray($data);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function malformedPools(): iterable
    {
        $valid = self::sneak()->toArray();
        $pool = $valid['pool'] ?? [];
        $with = static fn (array $change): array => ['pool' => $change + $pool] + $valid;
        $die = static fn (int $face, bool $success): array => ['group' => 'pool', 'face' => $face, 'success' => $success];

        yield 'a pool that is not an object' => [['pool' => 'many'] + $valid, '"pool" must be an object or null'];
        yield 'a pool that is a list' => [['pool' => [1, 2]] + $valid, '"pool" must be an object or null'];
        foreach (['count', 'size', 'sides', 'successAt', 'successes'] as $field) {
            yield \sprintf('%s not an integer', $field) => [$with([$field => '2']), \sprintf('"%s" must be an integer', $field)];
        }
        yield 'count formula not a string' => [$with(['countFormula' => 7]), '"countFormula" must be a string'];
        yield 'blank count formula' => [$with(['countFormula' => ' ', 'terms' => []]), 'A pool count formula must not be blank.'];
        yield 'terms without a formula' => [$with(['countFormula' => null]), '"terms" must be empty when the pool count is not a formula'];
        yield 'terms not a list' => [$with(['terms' => 'none']), '"terms" must be a list'];
        yield 'dice not a list' => [$with(['dice' => 'none']), '"dice" must be a list'];
        yield 'die without a group' => [$with(['dice' => [['face' => 6, 'success' => true]]]), '"group" must be a string'];
        yield 'die face not an integer' => [$with(['dice' => [['group' => 'pool', 'face' => '6', 'success' => true]]]), '"face" must be an integer'];
        yield 'die success not a boolean' => [$with(['dice' => [['group' => 'pool', 'face' => 6, 'success' => 1]]]), '"success" must be a boolean'];
        yield 'one side' => [$with(['sides' => 1, 'successAt' => 1]), '"sides" must be between 2 and 1000'];
        yield 'too many sides' => [$with(['sides' => 1001]), '"sides" must be between 2 and 1000'];
        yield 'success threshold under 1' => [$with(['successAt' => 0]), '"successAt" must be between 1 and the sides'];
        yield 'success threshold over the sides' => [$with(['successAt' => 7]), '"successAt" must be between 1 and the sides'];
        yield 'size that is not the count plus the situational modifier' => [$with(['size' => 2, 'dice' => [$die(6, true), $die(5, true)], 'successes' => 2]) + ['expression' => '2d6'], '"size" must be the count plus the situational modifier'];
        yield 'size that is not the number of dice' => [$with(['dice' => [$die(6, true), $die(2, false)], 'successes' => 1]), '"size" must be the count plus the situational modifier'];
        yield 'a die of another group' => [$with(['dice' => [['group' => 'hunger'] + $die(6, true), $die(2, false), $die(5, true)]]), '"group" must be "pool"'];
        yield 'a face over the sides' => [$with(['dice' => [$die(7, true), $die(2, false), $die(5, true)]]), '"face" must be between 1 and the sides'];
        yield 'a face under 1' => [$with(['dice' => [$die(0, false), $die(2, false), $die(5, true)], 'successes' => 1]), '"face" must be between 1 and the sides'];
        yield 'a success that disagrees with its face' => [$with(['dice' => [$die(6, true), $die(2, true), $die(5, true)], 'successes' => 3]), '"success" must be whether the face reaches the success threshold'];
        yield 'successes that are not those of the dice' => [$with(['successes' => 3]), '"successes" must be the number of dice that succeeded'];
        yield 'an expression with another size' => [['expression' => '4d6'] + $valid, '"expression" must be the size and sides of the pool'];
        yield 'an expression with other sides' => [['expression' => '3d8'] + $valid, '"expression" must be the size and sides of the pool'];
        yield 'an expression that is not dice notation' => [['expression' => 'pool'] + $valid, '"expression" must be the size and sides of the pool'];
        yield 'a total that is not the successes' => [['total' => 3, 'diceTotal' => 3] + $valid, '"total" must be the successes of the pool'];
        yield 'a dice total that is not the successes' => [['diceTotal' => 3] + $valid, '"total" must be the successes of the pool'];
        yield 'dice groups beside a pool' => [['groups' => [['notation' => '1d6', 'sides' => 6, 'dice' => [], 'subtotal' => 0]]] + $valid, '"pool" must be alone'];
        yield 'a modifier beside a pool' => [['modifier' => 1] + $valid, '"pool" must be alone'];
        yield 'a modifier formula beside a pool' => [['modifierFormula' => '{agility}'] + $valid, '"pool" must be alone'];
    }
}
