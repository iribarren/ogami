<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\FlowRun\FlowRun;
use App\Play\Domain\Campaign\FlowRun\FlowStepView;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Domain\Campaign\FlowRun\ShopLine;
use App\Play\Domain\Campaign\FlowRun\ShopStock;
use App\Play\Domain\Campaign\FlowRun\ShopTrade;
use App\Play\Domain\Campaign\FlowRun\StepCannotBeSkipped;
use App\Play\Domain\Campaign\FlowRun\StepResult;
use App\Play\Domain\Campaign\InsufficientFunds;
use App\Play\Domain\Campaign\InventoryFull;
use App\Play\Domain\GameSystem\Flow\ShopStep;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\GearEntry;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shop step (ADR 0045), on valid/v2-sheet-effects with a gear catalog (knife 10, rope 5 of two
 * slots, jacket 40 of three slots: Gear; bread 3: Food; eye and ear 100 of no slot: Chrome), the
 * currency "gold" (floor 0) and three inventories in sheet order: "pack" (Gear, 4 slots, 2 stacks),
 * "larder" (Gear and Food) and "chrome" (Chrome, 1 stack). The Setup scene's character step "you"
 * leads to the shop "market", which offers every category, buys at 50 percent and adds 1 to dread,
 * then to the prompt "after". Ida carries a knife and a rope in her pack.
 */
#[CoversClass(FlowRun::class)]
#[CoversClass(ShopStock::class)]
#[CoversClass(ShopTrade::class)]
#[CoversClass(InventoryFull::class)]
final class FlowRunShopTest extends FlowRunTestCase
{
    #[Test]
    public function reachingAShopWithAStockDrawsItsEntriesOnceAndListsThemInCatalogOrder(): void
    {
        $release = $this->content(stock: 2);
        // The fifth of six entries (eye), then the first of the five left (knife).
        $campaign = $this->atTheMarket($release, new ScriptedRandomNumberGenerator(5, 1));

        self::assertEquals(new ShopStock('market', ['knife', 'eye']), self::flowRunOf($campaign)->shopStock());
        self::assertSame(['knife', 'eye'], $this->onSale($campaign, $release));
    }

    /**
     * @return iterable<string, array{string, list<int>, list<string>}> the dice, the numbers rolled and drawn, and the entries on sale
     */
    public static function diceStocks(): iterable
    {
        yield 'a roll within the offer' => ['1d4', [1, 6], ['ear']];
        yield 'a roll above the offer puts the whole offer on sale' => ['1d4+5', [4], ['knife', 'rope', 'jacket', 'bread', 'eye', 'ear']];
        yield 'a roll of nothing puts nothing on sale' => ['1d4-4', [4], []];
    }

    /**
     * @param list<int>    $numbers
     * @param list<string> $onSale
     */
    #[Test]
    #[DataProvider('diceStocks')]
    public function aDiceStockClampsToTheOffer(string $dice, array $numbers, array $onSale): void
    {
        $release = $this->content(stock: ['dice' => $dice]);
        $campaign = $this->atTheMarket($release, new ScriptedRandomNumberGenerator(...$numbers));

        self::assertSame($onSale, $this->onSale($campaign, $release));
    }

    #[Test]
    public function aShopReachedWhenASessionStartsDrawsItsStockToo(): void
    {
        $release = $this->content(stock: 1, atSessionOpening: true);
        $campaign = self::guided($release, 'play');
        $campaign->addCharacter(CharacterId::fromString('ida'), 'Ida', [], [], ['pow' => 60], $release);

        $campaign->startSession(self::at(), $release, new ScriptedRandomNumberGenerator(2));

        self::assertSame('market', self::flowRunOf($campaign)->stepKey());
        self::assertEquals(new ShopStock('market', ['rope']), self::flowRunOf($campaign)->shopStock());
    }

    #[Test]
    public function withoutAStockTheWholeOfferIsOnSaleAndNothingIsStored(): void
    {
        $release = $this->content();
        $campaign = $this->atTheMarket($release);

        self::assertNull(self::flowRunOf($campaign)->shopStock());
        self::assertSame(['knife', 'rope', 'jacket', 'bread', 'eye', 'ear'], $this->onSale($campaign, $release));
    }

    #[Test]
    public function theStockStaysThroughARefusalAndIsClearedOnceTheStepCompletes(): void
    {
        $release = $this->content(stock: 2);
        $campaign = $this->atTheMarket($release, new ScriptedRandomNumberGenerator(5, 1));

        $this->assertRefused($campaign, $release, [ShopLine::buy('rope', 1)], [], InvalidStepResult::class, 'Shop step "market" has no "rope" on sale.');
        self::assertEquals(new ShopStock('market', ['knife', 'eye']), self::flowRunOf($campaign)->shopStock());

        $campaign->completeFlowStep('market', StepResult::shop('ida', [ShopLine::buy('knife', 1)], []), $release, self::at());
        self::assertNull(self::flowRunOf($campaign)->shopStock());
        self::assertSame(['play', 'after'], self::position($campaign));
    }

    #[Test]
    public function aPurchaseLandsOnItsStackOrInTheFirstInventoryThatAcceptsIt(): void
    {
        $release = $this->content();
        $campaign = $this->atTheMarket($release, gold: 200);

        $campaign->completeFlowStep('market', StepResult::shop('ida', [ShopLine::buy('bread', 2), ShopLine::buy('eye', 1), ShopLine::buy('knife', 1)], []), $release, self::at());

        self::assertSame([['gear' => 'knife', 'quantity' => 2], ['gear' => 'rope', 'quantity' => 1]], $this->value($campaign, 'pack'));
        self::assertSame([['gear' => 'bread', 'quantity' => 2]], $this->value($campaign, 'larder'));
        self::assertSame([['gear' => 'eye', 'quantity' => 1]], $this->value($campaign, 'chrome'));
        self::assertSame(200 - 6 - 100 - 10, $this->value($campaign, 'gold'));
        self::assertSame(1, $campaign->trackerValuesOf($release)['dread'], 'the step\'s own effects apply too');
        self::assertSame(['play', 'after'], self::position($campaign));
        self::assertSame('Bought: Bread, Cheap eye, Knife.', $this->afterTitle($campaign, $release));
    }

    #[Test]
    public function salesComeFirstSoTheirMoneyAndSlotsPayForThePurchases(): void
    {
        $release = $this->content();
        // A knife sells for 5, a rope for 2 (half of 5, rounded down); the jacket costs 40 and takes 3 slots.
        $campaign = $this->atTheMarket($release, gold: 33);

        $campaign->completeFlowStep('market', StepResult::shop('ida', [ShopLine::buy('jacket', 1)], [ShopLine::sell('pack', 'knife', 1), ShopLine::sell('pack', 'rope', 1)]), $release, self::at());

        self::assertSame([['gear' => 'jacket', 'quantity' => 1]], $this->value($campaign, 'pack'));
        self::assertSame(0, $this->value($campaign, 'gold'));
    }

    #[Test]
    public function anEmptyTransactionLeavesTheShop(): void
    {
        $release = $this->content();
        $campaign = $this->atTheMarket($release);

        self::assertSame([], $campaign->completeFlowStep('market', StepResult::shop('ida', [], []), $release, self::at()));

        self::assertSame(100, $this->value($campaign, 'gold'));
        self::assertSame(['play', 'after'], self::position($campaign));
        self::assertSame('Bought: .', $this->afterTitle($campaign, $release));
    }

    /**
     * @return iterable<string, array{list<ShopLine>, list<ShopLine>, class-string<\Throwable>, string}>
     */
    public static function refusedTransactions(): iterable
    {
        yield 'unknown gear' => [[ShopLine::buy('katana', 1)], [], InvalidStepResult::class, 'Shop step "market" has no "katana" on sale.'];
        yield 'a quantity of zero' => [[ShopLine::buy('knife', 0)], [], InvalidStepResult::class, 'Shop step "market" trades 1 to 9999 units of "knife", got 0.'];
        yield 'a quantity of 10,000' => [[], [ShopLine::sell('pack', 'knife', 10000)], InvalidStepResult::class, 'Shop step "market" trades 1 to 9999 units of "knife", got 10000.'];
        yield 'a gear bought on two lines' => [[ShopLine::buy('knife', 1), ShopLine::buy('knife', 1)], [], InvalidStepResult::class, 'Shop step "market" lists "knife" twice.'];
        yield 'a stack sold on two lines' => [[], [ShopLine::sell('pack', 'knife', 1), ShopLine::sell('pack', 'knife', 1)], InvalidStepResult::class, 'Shop step "market" lists "knife" twice.'];
        yield 'an over-sell' => [[], [ShopLine::sell('pack', 'knife', 2)], InvalidStepResult::class, 'Shop step "market" cannot buy 2 "knife" from field "pack", which holds 1.'];
        yield 'a stack the field does not hold' => [[], [ShopLine::sell('larder', 'knife', 1)], InvalidStepResult::class, 'Shop step "market" cannot buy 1 "knife" from field "larder", which holds 0.'];
        yield 'a field that is no inventory' => [[], [ShopLine::sell('gold', 'knife', 1)], InvalidStepResult::class, 'Shop step "market" cannot buy from field "gold": it is no inventory field.'];
        yield 'more than the Character has' => [[ShopLine::buy('bread', 101)], [], InsufficientFunds::class, 'Not enough "gold": the step would take it from 300 to -3, under 0.'];
        yield 'a purchase over the capacity, never spilled into another field' => [[ShopLine::buy('knife', 2)], [], InventoryFull::class, 'Field "pack" holds 4 slots: the purchase needs 5.'];
        yield 'a purchase over the stacks' => [[ShopLine::buy('eye', 1), ShopLine::buy('ear', 1)], [], InventoryFull::class, 'Field "chrome" holds 1 stack: the purchase needs 2.'];
    }

    /**
     * @param list<ShopLine>           $buy
     * @param list<ShopLine>           $sell
     * @param class-string<\Throwable> $exception
     */
    #[Test]
    #[DataProvider('refusedTransactions')]
    public function aRefusedTransactionChangesNothing(array $buy, array $sell, string $exception, string $message): void
    {
        $release = $this->content();
        $campaign = $this->atTheMarket($release, gold: 300);

        $this->assertRefused($campaign, $release, $buy, $sell, $exception, $message);
    }

    #[Test]
    public function aShopThatDoesNotBuyRefusesASale(): void
    {
        $release = $this->content(sellRate: null);
        $campaign = $this->atTheMarket($release);

        $this->assertRefused($campaign, $release, [], [ShopLine::sell('pack', 'knife', 1)], InvalidStepResult::class, 'Shop step "market" does not buy.');
    }

    #[Test]
    public function aShopNeedsACharacterOfTheCampaign(): void
    {
        $release = $this->content();
        $campaign = $this->atTheMarket($release);

        $this->expectException(InvalidStepResult::class);
        $campaign->completeFlowStep('market', StepResult::shop('nobody', [], []), $release, self::at());
    }

    #[Test]
    public function aShopIsNeverSkipped(): void
    {
        $release = $this->content();
        $campaign = $this->atTheMarket($release);

        $this->expectException(StepCannotBeSkipped::class);
        $campaign->skipFlowStep('market', $release, self::at());
    }

    #[Test]
    public function theTradeTellsTheLinesWithTheirUnitPricesAndTheCurrencyBeforeAndAfter(): void
    {
        $release = $this->content();
        $campaign = $this->atTheMarket($release, gold: 50);
        $market = $release->sceneType('setup')?->play->step('market');
        self::assertInstanceOf(ShopStep::class, $market);

        $trade = ShopTrade::of($market, $market->offer, $release->sheet(), $campaign->character(CharacterId::fromString('ida'))?->values() ?? [], [ShopLine::buy('bread', 3)], [ShopLine::sell('pack', 'rope', 1)]);

        self::assertSame([['gear' => 'bread', 'name' => 'Bread', 'quantity' => 3, 'price' => 3]], $trade->bought);
        self::assertSame([['field' => 'pack', 'gear' => 'rope', 'name' => 'Rope', 'quantity' => 1, 'price' => 2]], $trade->sold);
        self::assertSame([50, 43], [$trade->before, $trade->after]);
        self::assertSame('Bread', $trade->boughtNames());
    }

    /**
     * @param list<ShopLine>           $buy
     * @param list<ShopLine>           $sell
     * @param class-string<\Throwable> $exception
     */
    private function assertRefused(Campaign $campaign, GameSystemSnapshot $release, array $buy, array $sell, string $exception, string $message): void
    {
        $unchanged = clone $campaign;

        try {
            $campaign->completeFlowStep('market', StepResult::shop('ida', $buy, $sell), $release, self::at());
            self::fail('The transaction was not refused.');
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e);
            self::assertSame($message, $e->getMessage());
        }

        self::assertEquals($unchanged, $campaign);
    }

    /**
     * @param int|array{dice: string}|null $stock
     */
    private function content(int|array|null $stock = null, ?int $sellRate = 50, bool $atSessionOpening = false): GameSystemSnapshot
    {
        /** @var array{sheet: array{fields: list<array<string, mixed>>}} $content */
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-sheet-effects'), 'gear', [
            ['key' => 'knife', 'name' => 'Knife', 'cost' => 10, 'category' => 'Gear'],
            ['key' => 'rope', 'name' => 'Rope', 'cost' => 5, 'slots' => 2, 'category' => 'Gear'],
            ['key' => 'jacket', 'name' => 'Jacket', 'cost' => 40, 'slots' => 3, 'category' => 'Gear'],
            ['key' => 'bread', 'name' => 'Bread', 'cost' => 3, 'category' => 'Food'],
            ['key' => 'eye', 'name' => 'Cheap eye', 'cost' => 100, 'slots' => 0, 'category' => 'Chrome'],
            ['key' => 'ear', 'name' => 'Cheap ear', 'cost' => 100, 'slots' => 0, 'category' => 'Chrome'],
        ]);
        $content = ReleaseArrays::with($content, 'sheet.fields', [
            ...$content['sheet']['fields'],
            ['key' => 'gold', 'label' => 'Gold', 'type' => 'number', 'min' => 0],
            ['key' => 'pack', 'label' => 'Pack', 'type' => 'inventory', 'capacity' => 4, 'maxStacks' => 2, 'categories' => ['Gear']],
            ['key' => 'larder', 'label' => 'Larder', 'type' => 'inventory', 'categories' => ['Gear', 'Food']],
            ['key' => 'chrome', 'label' => 'Chrome', 'type' => 'inventory', 'maxStacks' => 1, 'categories' => ['Chrome']],
        ]);
        $content = ReleaseArrays::with($content, 'sheet.currency', ['field' => 'gold', 'unit' => 'eb']);
        $market = ['key' => 'market', 'kind' => 'shop', 'title' => 'What do you buy?', 'offer' => ['categories' => ['Gear', 'Food', 'Chrome']], 'effects' => [['kind' => 'tracker', 'tracker' => 'dread', 'op' => 'add', 'value' => 1]]];
        $market += null === $stock ? [] : ['stock' => $stock];
        $market += null === $sellRate ? [] : ['sellRate' => $sellRate];
        // The shop is the session opening of the phase instead of the first step of the Setup scene's play.
        $content = ReleaseArrays::with($content, 'flows', [['key' => 'play', 'name' => 'Play', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => ['dread'], 'phases' => [
            ['key' => 'prologue', 'name' => 'Prologue', 'mode' => 'once', 'selection' => ['rule' => 'player', 'sceneTypes' => ['setup']], ...($atSessionOpening ? ['sessionOpening' => [$market]] : [])],
        ]]]);

        /** @var array<string, mixed> $translated */
        $translated = ReleaseArrays::with($content, 'sceneTypes.0.play', [...($atSessionOpening ? [] : [$market]), ['key' => 'after', 'kind' => 'prompt', 'title' => 'Bought: {step:market}.']]);

        return self::translate($translated);
    }

    /**
     * Ida (a knife and a rope in her pack, 100 gold unless told otherwise) completes the character
     * step of the Setup scene, which reaches the shop.
     */
    private function atTheMarket(GameSystemSnapshot $release, ?ScriptedRandomNumberGenerator $random = null, int $gold = 100): Campaign
    {
        $campaign = self::guided($release, 'play');
        $campaign->startSession(self::at(), $release);
        $campaign->addCharacter(CharacterId::fromString('ida'), 'Ida', [], [], ['pow' => 60, 'gold' => $gold, 'pack' => [['gear' => 'knife', 'quantity' => 1], ['gear' => 'rope', 'quantity' => 1]]], $release);
        $campaign->editCurrentSceneCast([], [CharacterId::fromString('ida')]);
        $campaign->completeFlowStep('you', StepResult::character('ida'), $release, self::at(), $random ?? new ScriptedRandomNumberGenerator());
        self::assertSame(['play', 'market'], self::position($campaign));

        return $campaign;
    }

    /**
     * @return list<string>
     */
    private function onSale(Campaign $campaign, GameSystemSnapshot $release): array
    {
        $step = $campaign->activeFlowRunView($release)->step;
        self::assertInstanceOf(FlowStepView::class, $step);

        return array_map(static fn (GearEntry $entry): string => $entry->key, $step->shop->onSale ?? []);
    }

    private function afterTitle(Campaign $campaign, GameSystemSnapshot $release): ?string
    {
        return $campaign->activeFlowRunView($release)->step?->title;
    }

    private function value(Campaign $campaign, string $field): mixed
    {
        return $campaign->character(CharacterId::fromString('ida'))?->values()[$field] ?? null;
    }
}
