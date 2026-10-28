<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\AddCharacter;
use App\Play\Application\Clock;
use App\Play\Application\CreateCampaign;
use App\Play\Application\JournalEntryIdGenerator;
use App\Play\Application\PickSceneType;
use App\Play\Application\StartSession;
use App\Play\Infrastructure\Http\FlowRunController;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Play\RescriptableRandomNumberGenerator;
use App\Tests\Support\Play\UuidSequenceJournalEntryIdGenerator;
use App\Tests\Support\Studio\ReleaseArrays;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The shop step over the Play API (ADR 0045), on valid/v2-checks with a gear catalog (knife 10 and
 * rope 5 of two slots: Gear; eye 100 of no slot: Chrome), the currency "gold" (30, floor 0) and the
 * inventories "pack" (Gear, 4 slots) and "chrome" (Chrome, 1 stack). The Scene Type "market" holds
 * the shop "stalls" (every category, buys at 50 percent), "bazaar" the shop "stand" (one entry of
 * the three drawn, does not buy); each is followed by the prompt "after". Ada carries a knife.
 */
#[CoversClass(FlowRunController::class)]
final class CampaignFlowShopApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string CAMPAIGN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9831';
    private const string ENTRY_ID_PREFIX = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f84';

    private KernelBrowser $client;
    private CommandBus $commands;
    private RescriptableRandomNumberGenerator $random;
    private string $userId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $container->set(Clock::class, new FixedClock('2026-10-14T09:00:00+00:00'));
        $container->set(JournalEntryIdGenerator::class, new UuidSequenceJournalEntryIdGenerator(self::ENTRY_ID_PREFIX));
        $container->set(RandomNumberGenerator::class, $this->random = new RescriptableRandomNumberGenerator());
        $this->commands = $container->get(CommandBus::class);
        $this->commands->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9432', $this->release(), false));
        $this->userId = $container->get(UserIdGenerator::class)->generate()->toString();
        $this->commands->dispatch(new CreateUser($this->userId, 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function aShopStepShowsWhatIsOnSaleTheSellRateAndTheCurrency(): void
    {
        $this->inScene('market');

        $step = self::at($this->campaign(), 'flowRun', 'step');

        self::assertSame('shop', self::at($step, 'kind'));
        self::assertSame([
            'onSale' => [
                ['key' => 'knife', 'name' => 'Knife', 'cost' => 10, 'slots' => 1, 'category' => 'Gear', 'description' => null],
                ['key' => 'rope', 'name' => 'Rope', 'cost' => 5, 'slots' => 2, 'category' => 'Gear', 'description' => null],
                ['key' => 'eye', 'name' => 'Cheap eye', 'cost' => 100, 'slots' => 0, 'category' => 'Chrome', 'description' => null],
            ],
            'sellRate' => 50,
            'currency' => ['field' => 'gold', 'unit' => 'eb'],
        ], self::at($step, 'shop'));
        self::assertFalse(self::at($step, 'mandatory'));
    }

    #[Test]
    public function aStockIsDrawnWhenTheShopIsReachedAndStaysAcrossReads(): void
    {
        // The second of the three entries: the rope.
        $this->random->script(2);
        $this->inScene('bazaar');

        $first = self::at($this->campaign(), 'flowRun', 'step', 'shop');
        $second = self::at($this->campaign(), 'flowRun', 'step', 'shop');

        self::assertSame([['key' => 'rope', 'name' => 'Rope', 'cost' => 5, 'slots' => 2, 'category' => 'Gear', 'description' => null]], self::at($first, 'onSale'));
        self::assertNull(self::at($first, 'sellRate'));
        self::assertSame($first, $second);
    }

    #[Test]
    public function aTransactionCompletesTheStepAndRecordsOneEntry(): void
    {
        $this->inScene('market');

        $this->answer(['stepKey' => 'stalls', 'characterId' => 'char-ada', 'buy' => [['gear' => 'rope', 'quantity' => 2]], 'sell' => [['field' => 'pack', 'gear' => 'knife', 'quantity' => 1]]]);

        self::assertResponseIsSuccessful();
        $campaign = $this->json();
        self::assertSame(['gold' => 25, 'pack' => [['gear' => 'rope', 'quantity' => 2]]], [
            'gold' => self::at($this->ada($campaign), 'values', 'gold'),
            'pack' => self::at($this->ada($campaign), 'values', 'pack'),
        ]);
        self::assertSame('Bought: Rope.', self::at($campaign, 'flowRun', 'step', 'title'));
        $journal = $this->journal();
        self::assertCount(1, $journal);
        self::assertSame([
            'kind' => 'shop',
            'characterId' => 'char-ada',
            'characterName' => 'Ada',
            'bought' => [['field' => null, 'gear' => 'rope', 'name' => 'Rope', 'quantity' => 2, 'price' => 5]],
            'sold' => [['field' => 'pack', 'gear' => 'knife', 'name' => 'Knife', 'quantity' => 1, 'price' => 5]],
            'before' => 30,
            'after' => 25,
        ], self::at($journal[0], 'content'));
    }

    #[Test]
    public function anEmptyTransactionLeavesTheShop(): void
    {
        $this->inScene('market');

        $this->answer(['stepKey' => 'stalls', 'characterId' => 'char-ada']);

        self::assertResponseIsSuccessful();
        self::assertSame('Bought: .', self::at($this->json(), 'flowRun', 'step', 'title'));
        self::assertSame([[], [], 30, 30], [self::at($this->journal()[0], 'content', 'bought'), self::at($this->journal()[0], 'content', 'sold'), self::at($this->journal()[0], 'content', 'before'), self::at($this->journal()[0], 'content', 'after')]);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, int, array<string, string>}> the Scene Type, the body, the status and the error body
     */
    public static function refusedTransactions(): iterable
    {
        $ada = ['stepKey' => 'stalls', 'characterId' => 'char-ada'];
        yield 'a purchase over the capacity' => ['market', [...$ada, 'buy' => [['gear' => 'rope', 'quantity' => 2]]], 422, ['error' => 'Field "pack" holds 4 slots: the purchase needs 5.', 'code' => 'inventory_full']];
        yield 'more than the Character has' => ['market', [...$ada, 'buy' => [['gear' => 'eye', 'quantity' => 1]]], 422, ['error' => 'Not enough "gold": the step would take it from 30 to -70, under 0.', 'code' => 'insufficient_funds']];
        yield 'unknown gear' => ['market', [...$ada, 'buy' => [['gear' => 'katana', 'quantity' => 1]]], 422, ['error' => 'Shop step "stalls" has no "katana" on sale.']];
        yield 'gear the stock does not hold' => ['bazaar', ['stepKey' => 'stand', 'characterId' => 'char-ada', 'buy' => [['gear' => 'knife', 'quantity' => 1]]], 422, ['error' => 'Shop step "stand" has no "knife" on sale.']];
        yield 'a sale where the shop does not buy' => ['bazaar', ['stepKey' => 'stand', 'characterId' => 'char-ada', 'sell' => [['field' => 'pack', 'gear' => 'knife', 'quantity' => 1]]], 422, ['error' => 'Shop step "stand" does not buy.']];
        yield 'an over-sell' => ['market', [...$ada, 'sell' => [['field' => 'pack', 'gear' => 'knife', 'quantity' => 2]]], 422, ['error' => 'Shop step "stalls" cannot buy 2 "knife" from field "pack", which holds 1.']];
        yield 'a quantity of zero' => ['market', [...$ada, 'buy' => [['gear' => 'rope', 'quantity' => 0]]], 422, ['error' => 'Shop step "stalls" trades 1 to 9999 units of "rope", got 0.']];
        yield 'no Character' => ['market', ['stepKey' => 'stalls'], 422, ['error' => 'Step "stalls" is a shop step: it needs "characterId".']];
        yield 'an unknown Character' => ['market', ['stepKey' => 'stalls', 'characterId' => 'char-nobody'], 422, ['error' => 'Shop step "stalls" has no Character "char-nobody" in the campaign.']];
        yield 'a field of another kind' => ['market', [...$ada, 'optionKey' => 'yes'], 422, ['error' => 'Step "stalls" is a shop step: it takes no "optionKey".']];
        yield 'purchases sent to another kind of step' => ['market', ['stepKey' => 'stalls', 'buy' => 'rope'], 400, ['error' => 'Send a JSON object with a string "stepKey", and optionally a string "text", a string "optionKey", a string "likelihood", an integer "chaosFactor", a list of strings "pickedIds", a string "characterId", an integer "difficulty", an integer "situational", a string "checkKey", a list "buy" of {gear, quantity} and a list "sell" of {field, gear, quantity}, such as {"stepKey": "intro", "text": "Ada, a fixer"}.']];
        yield 'a sale line without its field' => ['market', [...$ada, 'sell' => [['gear' => 'knife', 'quantity' => 1]]], 400, ['error' => 'Send a JSON object with a string "stepKey", and optionally a string "text", a string "optionKey", a string "likelihood", an integer "chaosFactor", a list of strings "pickedIds", a string "characterId", an integer "difficulty", an integer "situational", a string "checkKey", a list "buy" of {gear, quantity} and a list "sell" of {field, gear, quantity}, such as {"stepKey": "intro", "text": "Ada, a fixer"}.']];
        yield 'a quantity that is a string' => ['market', [...$ada, 'buy' => [['gear' => 'rope', 'quantity' => '1']]], 400, ['error' => 'Send a JSON object with a string "stepKey", and optionally a string "text", a string "optionKey", a string "likelihood", an integer "chaosFactor", a list of strings "pickedIds", a string "characterId", an integer "difficulty", an integer "situational", a string "checkKey", a list "buy" of {gear, quantity} and a list "sell" of {field, gear, quantity}, such as {"stepKey": "intro", "text": "Ada, a fixer"}.']];
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $error
     */
    #[Test]
    #[DataProvider('refusedTransactions')]
    public function aRefusedTransactionChangesAndRecordsNothing(string $sceneType, array $body, int $status, array $error): void
    {
        $this->random->script(2);
        $this->inScene($sceneType);
        $before = $this->campaign();

        $this->answer($body);

        self::assertResponseStatusCodeSame($status);
        self::assertSame($error, $this->json());
        self::assertSame($before, $this->campaign());
        self::assertSame([], $this->journal());
    }

    #[Test]
    public function purchasesSentToAStepOfAnotherKindAreUnprocessable(): void
    {
        $this->inScene('market');
        $this->answer(['stepKey' => 'stalls', 'characterId' => 'char-ada']);

        $this->answer(['stepKey' => 'after', 'text' => 'Done', 'buy' => [['gear' => 'rope', 'quantity' => 1]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Step "after" is a prompt step: it takes no "buy".'], $this->json());
    }

    #[Test]
    public function aShopIsNeverSkipped(): void
    {
        $this->inScene('market');

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/flow-run/skip', self::CAMPAIGN), ['stepKey' => 'stalls']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Shop step "stalls" is never skipped: leave it with an empty transaction.'], $this->json());
    }

    #[Test]
    public function aTransactionForAnotherStepIsAConflict(): void
    {
        $this->inScene('market');

        $this->answer(['stepKey' => 'after', 'characterId' => 'char-ada']);

        self::assertResponseStatusCodeSame(409);
    }

    #[Test]
    public function aTransactionRacingAnotherSaveIsAConflictAndChangesNothing(): void
    {
        $this->inScene('market');
        $before = $this->campaign();
        // Another request saves the campaign after this one loaded it, right before it flushes.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $concurrentSave = new readonly class($entityManager->getConnection(), self::CAMPAIGN) {
            public function __construct(private Connection $connection, private string $campaignId)
            {
            }

            public function preFlush(): void
            {
                $this->connection->executeStatement('UPDATE play_campaign SET version = version + 1 WHERE id = ?', [$this->campaignId]);
            }
        };
        $entityManager->getEventManager()->addEventListener([Events::preFlush], $concurrentSave);

        try {
            $this->answer(['stepKey' => 'stalls', 'characterId' => 'char-ada', 'sell' => [['field' => 'pack', 'gear' => 'knife', 'quantity' => 1]]]);
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentSave);
        }

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => \sprintf('Campaign "%s" was changed by another request. Reload it and try again.', self::CAMPAIGN)], $this->json());
        $entityManager->clear();
        self::assertSame(self::at($before, 'characters'), self::at($this->campaign(), 'characters'));
        self::assertSame([], $this->journal());
    }

    /**
     * @return array<string, mixed>
     */
    private function release(): array
    {
        $base = ReleaseViews::fixtureContent('valid/v2-checks');
        $after = ['key' => 'after', 'kind' => 'prompt', 'title' => 'Bought: {step:stalls}.'];
        $sceneTypes = [
            'market' => ['play' => [['key' => 'stalls', 'kind' => 'shop', 'title' => 'What do you buy?', 'offer' => ['categories' => ['Gear', 'Chrome']], 'sellRate' => 50], $after]],
            'bazaar' => ['play' => [['key' => 'stand', 'kind' => 'shop', 'title' => 'What do you buy?', 'offer' => ['gear' => ['knife', 'rope', 'eye']], 'stock' => 1], $after]],
        ];
        $content = ReleaseArrays::with($base, 'sceneTypes', array_map(
            static fn (string $key, array $steps): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => [], ...$steps, 'closing' => []],
            array_keys($sceneTypes),
            $sceneTypes,
        ));
        $content = ReleaseArrays::with($content, 'gear', [
            ['key' => 'knife', 'name' => 'Knife', 'cost' => 10, 'category' => 'Gear'],
            ['key' => 'rope', 'name' => 'Rope', 'cost' => 5, 'slots' => 2, 'category' => 'Gear'],
            ['key' => 'eye', 'name' => 'Cheap eye', 'cost' => 100, 'slots' => 0, 'category' => 'Chrome'],
        ]);
        $content = ReleaseArrays::with($content, 'sheet.fields', [
            ...$this->fields($base),
            ['key' => 'gold', 'label' => 'Gold', 'type' => 'number', 'min' => 0, 'default' => 30],
            ['key' => 'pack', 'label' => 'Pack', 'type' => 'inventory', 'capacity' => 4, 'categories' => ['Gear']],
            ['key' => 'chrome', 'label' => 'Chrome', 'type' => 'inventory', 'maxStacks' => 1, 'categories' => ['Chrome']],
        ]);
        $content = ReleaseArrays::with($content, 'sheet.currency', ['field' => 'gold', 'unit' => 'eb']);

        /** @var array<string, mixed> $release */
        $release = ReleaseArrays::with($content, 'flows', [['key' => 'tour', 'name' => 'Tour', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
            ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => array_keys($sceneTypes)]],
        ]]]);

        return $release;
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return list<mixed>
     */
    private function fields(array $content): array
    {
        $fields = self::at($content, 'sheet', 'fields');
        self::assertIsArray($fields);

        return array_values($fields);
    }

    /**
     * A guided campaign in a scene of this Scene Type with Ada (30 gold, a knife in her pack).
     */
    private function inScene(string $sceneType): void
    {
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The market', 'checks', 'tour'));
        $this->dispatch(new AddCharacter(self::CAMPAIGN, $this->userId, 'char-ada', 'Ada', [], [], ['pack' => [['gear' => 'knife', 'quantity' => 1]]]));
        $this->dispatch(new StartSession(self::CAMPAIGN, $this->userId));
        $this->dispatch(new PickSceneType(self::CAMPAIGN, $this->userId, $sceneType));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function answer(array $body): void
    {
        $this->client->request('POST', \sprintf('/api/campaigns/%s/flow-run/answer', self::CAMPAIGN), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function dispatch(Command $command): void
    {
        $this->commands->dispatch($command);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @return array<mixed>
     */
    private function campaign(): array
    {
        $this->client->request('GET', '/api/campaigns/'.self::CAMPAIGN);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @return list<array<mixed>>
     */
    private function journal(): array
    {
        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', self::CAMPAIGN));
        self::assertResponseIsSuccessful();

        return array_values(array_filter($this->json(), \is_array(...)));
    }

    /**
     * @param array<mixed> $campaign
     */
    private function ada(array $campaign): mixed
    {
        $characters = self::at($campaign, 'characters');
        self::assertIsArray($characters);

        return array_first(array_filter($characters, static fn (mixed $character): bool => 'char-ada' === self::at($character, 'id')));
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private static function at(mixed $data, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $data = \is_array($data) && \array_key_exists($key, $data) ? $data[$key] : null;
        }

        return $data;
    }
}
