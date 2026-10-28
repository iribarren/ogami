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
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Play\UuidSequenceJournalEntryIdGenerator;
use App\Tests\Support\Studio\ReleaseArrays;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Affordability over the Play API (ADR 0045), on the release "choices" (valid/v2-checks) with a number
 * field "gold" (default 3) as its currency and the Flow "tour". "shop" holds the choice "buy" (option
 * "cheap" costs 2 gold, "dear" 5, "both" adds a hand and costs 5), "pay" the prompt "pay" (5 gold) and
 * "hero" the character step "who" (5 gold). Ada and Bo are in the campaign.
 */
#[CoversClass(FlowRunController::class)]
final class CampaignFlowAffordabilityApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string CAMPAIGN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9821';
    private const string ENTRY_ID_PREFIX = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f83';

    private KernelBrowser $client;
    private CommandBus $commands;
    private string $userId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $container->set(Clock::class, new FixedClock('2026-10-14T09:00:00+00:00'));
        $container->set(JournalEntryIdGenerator::class, new UuidSequenceJournalEntryIdGenerator(self::ENTRY_ID_PREFIX));
        $this->commands = $container->get(CommandBus::class);
        $this->commands->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9422', $this->release(), false));
        $this->userId = $container->get(UserIdGenerator::class)->generate()->toString();
        $this->commands->dispatch(new CreateUser($this->userId, 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}> the Scene Type and the body
     */
    public static function unaffordableAnswers(): iterable
    {
        yield 'a choice option' => ['shop', ['stepKey' => 'buy', 'optionKey' => 'dear', 'characterId' => 'char-ada']];
        yield 'a choice option whose earlier effect would have worked' => ['shop', ['stepKey' => 'buy', 'optionKey' => 'both', 'characterId' => 'char-ada']];
        yield 'a prompt' => ['pay', ['stepKey' => 'pay', 'text' => 'Paid', 'characterId' => 'char-ada']];
        yield 'a character step' => ['hero', ['stepKey' => 'who', 'characterId' => 'char-ada']];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('unaffordableAnswers')]
    public function aSpendTheCharacterCannotAffordIsUnprocessableAndChangesAndRecordsNothing(string $sceneType, array $body): void
    {
        $this->inScene($sceneType);
        $before = $this->campaign();

        $this->answer($body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Not enough "gold": the step would take it from 3 to -2, under 0.', 'code' => 'insufficient_funds'], $this->json());
        self::assertSame($before, $this->campaign());
        self::assertSame([], $this->journal());
    }

    #[Test]
    public function aSpendTheCharacterCanAffordCompletesAndTakesTheGold(): void
    {
        $this->inScene('shop');

        $this->answer(['stepKey' => 'buy', 'optionKey' => 'cheap', 'characterId' => 'char-ada']);

        self::assertResponseIsSuccessful();
        self::assertSame([1, 3], [$this->gold($this->json(), 'char-ada'), $this->gold($this->json(), 'char-bo')]);
        self::assertSame(['choice', 'sheet-change'], array_map(static fn (array $entry): mixed => self::at($entry, 'kind'), $this->journal()));
    }

    /**
     * @return array<string, mixed>
     */
    private function release(): array
    {
        $base = ReleaseViews::fixtureContent('valid/v2-checks');
        $gold = static fn (string $op, int $value, string $field = 'gold'): array => ['kind' => 'sheet', 'field' => $field, 'op' => $op, 'value' => $value];
        $after = ['key' => 'after', 'kind' => 'prompt', 'title' => 'After'];
        $sceneTypes = [
            'shop' => ['setup' => [], 'play' => [
                ['key' => 'buy', 'kind' => 'choice', 'title' => 'What do you buy?', 'skip' => 'free', 'options' => [
                    ['key' => 'free', 'label' => 'Nothing'],
                    ['key' => 'cheap', 'label' => 'Cheap', 'effects' => [$gold('subtract', 2)]],
                    ['key' => 'dear', 'label' => 'Dear', 'effects' => [$gold('subtract', 5)]],
                    ['key' => 'both', 'label' => 'Both', 'effects' => [$gold('add', 1, 'hands'), $gold('subtract', 5)]],
                ]],
                $after,
            ]],
            'pay' => ['setup' => [], 'play' => [['key' => 'pay', 'kind' => 'prompt', 'title' => 'What do you pay?', 'effects' => [$gold('subtract', 5)]], $after]],
            'hero' => ['setup' => [['key' => 'who', 'kind' => 'character', 'title' => 'Who are you?', 'effects' => [$gold('subtract', 5)]]], 'play' => [$after]],
        ];
        $content = ReleaseArrays::with($base, 'sceneTypes', array_map(
            static fn (string $key, array $steps): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], ...$steps, 'closing' => []],
            array_keys($sceneTypes),
            $sceneTypes,
        ));
        $content = ReleaseArrays::with($content, 'sheet.fields', [...$this->fields($base), ['key' => 'gold', 'label' => 'Gold', 'type' => 'number', 'default' => 3]]);
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
     * A guided campaign in a scene of this Scene Type with Ada and Bo (gold 3 each).
     */
    private function inScene(string $sceneType): void
    {
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, $this->userId, 'The break-in', 'checks', 'tour'));
        $this->dispatch(new AddCharacter(self::CAMPAIGN, $this->userId, 'char-ada', 'Ada', [], [], []));
        $this->dispatch(new AddCharacter(self::CAMPAIGN, $this->userId, 'char-bo', 'Bo', [], [], []));
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
    private function gold(array $campaign, string $characterId): mixed
    {
        $character = array_first(array_filter($this->list($campaign, 'characters'), static fn (mixed $character): bool => $characterId === self::at($character, 'id')));

        return self::at($character, 'values', 'gold');
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

    /**
     * @return list<mixed>
     */
    private function list(mixed $data, string $key): array
    {
        $list = self::at($data, $key);
        self::assertIsArray($list);

        return array_values($list);
    }

    private static function at(mixed $data, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $data = \is_array($data) && \array_key_exists($key, $data) ? $data[$key] : null;
        }

        return $data;
    }
}
