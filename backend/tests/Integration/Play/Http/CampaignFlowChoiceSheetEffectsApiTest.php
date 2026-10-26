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
use App\Play\Infrastructure\Http\FlowStepResponse;
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
 * Sheet effects on choice options and prompt steps over the Play API (ADR 0042), on the release
 * "choices" (valid/v2-checks, whose "hands" is a number from 0 to 10 starting at 4) and its Flow
 * "tour". "choose" holds the choice "learned" (option "yes" adds 1 to hands, "no" is the skip,
 * "maybe" changes nothing), "cost" the prompt "cost" (hands lose 2) and "plain" a prompt without
 * sheet effects. Ada and Bo are in the campaign.
 */
#[CoversClass(FlowRunController::class)]
#[CoversClass(FlowStepResponse::class)]
final class CampaignFlowChoiceSheetEffectsApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string CAMPAIGN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9811';
    private const string ENTRY_ID_PREFIX = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f82';

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
        $this->commands->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9412', $this->release(), false));
        $this->userId = $container->get(UserIdGenerator::class)->generate()->toString();
        $this->commands->dispatch(new CreateUser($this->userId, 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function aChoiceStepSaysWhichOptionsChangeTheSheet(): void
    {
        $this->inScene('choose');

        $step = self::at($this->campaign(), 'flowRun', 'step');

        self::assertSame([['yes', true], ['no', false], ['maybe', false]], array_map(static fn (mixed $option): array => [self::at($option, 'key'), self::at($option, 'changesSheet')], $this->list($step, 'options')));
        self::assertFalse(self::at($step, 'changesSheet'));
    }

    #[Test]
    public function aPromptStepSaysWhetherItChangesTheSheet(): void
    {
        $this->inScene('cost');
        $changing = self::at($this->campaign(), 'flowRun', 'step', 'changesSheet');
        $this->client->request('POST', \sprintf('/api/campaigns/%s/flow-run/skip', self::CAMPAIGN), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['stepKey' => 'cost'], \JSON_THROW_ON_ERROR));

        self::assertTrue($changing);
        self::assertFalse(self::at($this->campaign(), 'flowRun', 'step', 'changesSheet'), 'The next prompt changes no sheet.');
    }

    #[Test]
    public function aPickedOptionChangesTheSheetOfTheCharacterAndIsJournaledAfterTheChoice(): void
    {
        $this->inScene('choose');

        $this->answer(['stepKey' => 'learned', 'optionKey' => 'yes', 'characterId' => 'char-bo']);

        self::assertResponseIsSuccessful();
        self::assertSame([4, 5], [$this->hands($this->json(), 'char-ada'), $this->hands($this->json(), 'char-bo')]);
        $entries = $this->journal();
        self::assertSame([['choice', 'learned'], ['sheet-change', 'learned']], array_map(static fn (array $entry): array => [self::at($entry, 'kind'), self::at($entry, 'flowStep', 'key')], $entries));
        self::assertSame(['kind' => 'sheet-change', 'characterId' => 'char-bo', 'characterName' => 'Bo', 'changes' => [['field' => 'hands', 'before' => 4, 'after' => 5, 'dice' => null]]], self::at($entries[1], 'content'));
    }

    #[Test]
    public function aPromptChangesTheSheetOfTheCharacterAndIsJournaledAfterTheNote(): void
    {
        $this->inScene('cost');

        $this->answer(['stepKey' => 'cost', 'text' => 'It hurt', 'characterId' => 'char-ada']);

        self::assertResponseIsSuccessful();
        self::assertSame([2, 4], [$this->hands($this->json(), 'char-ada'), $this->hands($this->json(), 'char-bo')]);
        $entries = $this->journal();
        self::assertSame([['note', 'cost'], ['sheet-change', 'cost']], array_map(static fn (array $entry): array => [self::at($entry, 'kind'), self::at($entry, 'flowStep', 'key')], $entries));
        self::assertSame('char-ada', self::at($entries[1], 'content', 'characterId'));
    }

    #[Test]
    public function anOptionAndAPromptWithoutSheetEffectsAreAnsweredAsBefore(): void
    {
        $this->inScene('choose');

        $this->answer(['stepKey' => 'learned', 'optionKey' => 'maybe']);

        self::assertResponseIsSuccessful();
        self::assertSame([4, 4], [$this->hands($this->json(), 'char-ada'), $this->hands($this->json(), 'char-bo')]);
        self::assertSame(['choice'], array_map(static fn (array $entry): mixed => self::at($entry, 'kind'), $this->journal()));
    }

    #[Test]
    public function aSkippedChoiceChangesNoSheet(): void
    {
        $this->inScene('choose');

        $this->client->request('POST', \sprintf('/api/campaigns/%s/flow-run/skip', self::CAMPAIGN), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['stepKey' => 'learned'], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        self::assertSame([4, 4], [$this->hands($this->json(), 'char-ada'), $this->hands($this->json(), 'char-bo')]);
        self::assertSame([], array_values(array_filter($this->journal(), static fn (array $entry): bool => 'sheet-change' === self::at($entry, 'kind'))));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}> the Scene Type, the body and the error
     */
    public static function refusedAnswers(): iterable
    {
        yield 'an option with sheet effects and no Character' => ['choose', ['stepKey' => 'learned', 'optionKey' => 'yes'], 'Step "learned" is a choice step: it needs "characterId".'];
        yield 'an option with sheet effects and an unknown Character' => ['choose', ['stepKey' => 'learned', 'optionKey' => 'yes', 'characterId' => 'ghost'], 'Character step "learned" has no Character "ghost" in the campaign.'];
        yield 'an option without sheet effects and a Character' => ['choose', ['stepKey' => 'learned', 'optionKey' => 'maybe', 'characterId' => 'char-ada'], 'Step "learned" is a choice step: it takes no "characterId".'];
        yield 'a prompt with sheet effects and no Character' => ['cost', ['stepKey' => 'cost', 'text' => 'Ouch'], 'Step "cost" is a prompt step: it needs "characterId".'];
        yield 'a prompt with sheet effects and an unknown Character' => ['cost', ['stepKey' => 'cost', 'text' => 'Ouch', 'characterId' => 'ghost'], 'Character step "cost" has no Character "ghost" in the campaign.'];
        yield 'a prompt without sheet effects and a Character' => ['plain', ['stepKey' => 'note', 'text' => 'Fine', 'characterId' => 'char-ada'], 'Step "note" is a prompt step: it takes no "characterId".'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('refusedAnswers')]
    public function aRefusedAnswerIsUnprocessableAndChangesAndRecordsNothing(string $sceneType, array $body, string $error): void
    {
        $this->inScene($sceneType);
        $before = $this->campaign();

        $this->answer($body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($error, $this->error());
        self::assertSame($before, $this->campaign());
        self::assertSame([], $this->journal());
    }

    #[Test]
    public function aCharacterIdThatIsNotAStringIsABadRequest(): void
    {
        $this->inScene('choose');

        $this->answer(['stepKey' => 'learned', 'optionKey' => 'yes', 'characterId' => 7]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->journal());
    }

    /**
     * @return array<string, mixed>
     */
    private function release(): array
    {
        $base = ReleaseViews::fixtureContent('valid/v2-checks');
        $sheet = static fn (string $op, int $value): array => ['kind' => 'sheet', 'field' => 'hands', 'op' => $op, 'value' => $value];
        $sceneTypes = [
            'choose' => [
                ['key' => 'learned', 'kind' => 'choice', 'title' => 'Did you learn something?', 'skip' => 'no', 'options' => [
                    ['key' => 'yes', 'label' => 'Yes', 'effects' => [$sheet('add', 1)]],
                    ['key' => 'no', 'label' => 'No'],
                    ['key' => 'maybe', 'label' => 'Maybe'],
                ]],
                ['key' => 'after', 'kind' => 'prompt', 'title' => 'After'],
            ],
            'cost' => [
                ['key' => 'cost', 'kind' => 'prompt', 'title' => 'What did it cost you?', 'effects' => [$sheet('subtract', 2)]],
                ['key' => 'after', 'kind' => 'prompt', 'title' => 'After'],
            ],
            'plain' => [['key' => 'note', 'kind' => 'prompt', 'title' => 'A note'], ['key' => 'after', 'kind' => 'prompt', 'title' => 'After']],
        ];
        $content = ReleaseArrays::with($base, 'sceneTypes', array_map(
            static fn (string $key, array $play): array => ['key' => $key, 'name' => ucfirst($key), 'purpose' => 'A '.$key.' scene.', 'oracles' => [], 'setup' => [], 'play' => $play, 'closing' => []],
            array_keys($sceneTypes),
            $sceneTypes,
        ));

        /** @var array<string, mixed> $release */
        $release = ReleaseArrays::with($content, 'flows', [['key' => 'tour', 'name' => 'Tour', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
            ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => array_keys($sceneTypes)]],
        ]]]);

        return $release;
    }

    /**
     * A guided campaign in a scene of this Scene Type with Ada and Bo (hands 4 each).
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
    private function hands(array $campaign, string $characterId): mixed
    {
        $character = array_first(array_filter($this->list($campaign, 'characters'), static fn (mixed $character): bool => $characterId === self::at($character, 'id')));

        return self::at($character, 'values', 'hands');
    }

    private function error(): string
    {
        $error = $this->json()['error'] ?? null;
        self::assertIsString($error);

        return $error;
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
