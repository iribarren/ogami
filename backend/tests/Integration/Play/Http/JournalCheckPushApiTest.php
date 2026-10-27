<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\Clock;
use App\Play\Application\JournalEntryIdGenerator;
use App\Play\Application\PushCheckHandler;
use App\Play\Infrastructure\Http\CheckPushContentResponse;
use App\Play\Infrastructure\Http\CheckPushResponse;
use App\Play\Infrastructure\Http\JournalController;
use App\Play\Infrastructure\Http\JournalPushDieResponse;
use App\Randomness\Domain\RandomNumberGenerator;
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
 * Pushing a pool check over the Play API (ADR 0041), on a release "pushing" with the sheet agility,
 * zero (1), stress (0 to 10), hp (a track of 10), physical (hurt, exhausted, bruised) and mental
 * (shaken) conditions, the Tracker "dread" (a clock of 6) and the checks "grit" (4d6, success on 5+,
 * bands miss, weak up to 2, strong; push: stress +1 and dread +1), "wounded" (3d6; push: two picks
 * of physical), "mixed" (3d6; push: stress +2, hp -3, mark shaken, pick of physical), "cursed" (the
 * cost divides by zero), "fragile" (a band divides by zero once zero is 0), "plain" (no push) and
 * "knife" (dice). Vi (agility 3) is in the scene.
 *
 * @phpstan-type Pool array{dice: list<array<string, mixed>>, successes: int, push: array<string, mixed>|null}
 * @phpstan-type Content array{bandKey: string, bandLabel: string, total: int, diceTotal: int, pool: Pool}
 * @phpstan-type Entry array{id: string, content: Content}
 * @phpstan-type Character array{id: string, values: array<string, mixed>}
 * @phpstan-type Campaign array{characters: list<Character>, trackers: list<array{key: string, value: int}>, checks: list<array{key: string, push: mixed}>}
 */
#[CoversClass(JournalController::class)]
#[CoversClass(PushCheckHandler::class)]
#[CoversClass(CheckPushContentResponse::class)]
#[CoversClass(CheckPushResponse::class)]
#[CoversClass(JournalPushDieResponse::class)]
final class JournalCheckPushApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string ENTRY_ID_PREFIX = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f80';
    private const string BANDS = 'bands';

    private KernelBrowser $client;
    private RescriptableRandomNumberGenerator $random;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $container->set(Clock::class, new FixedClock('2026-10-25T09:00:00+00:00'));
        $container->set(JournalEntryIdGenerator::class, new UuidSequenceJournalEntryIdGenerator(self::ENTRY_ID_PREFIX));
        $this->random = new RescriptableRandomNumberGenerator();
        $container->set(RandomNumberGenerator::class, $this->random);

        $bands = [['key' => 'miss', 'label' => 'Miss', 'upTo' => 0], ['key' => 'weak', 'label' => 'Weak', 'upTo' => 2], ['key' => 'strong', 'label' => 'Strong']];
        $pool = ['count' => 4, 'sides' => 6, 'successAt' => 5];
        $mark = static fn (string $field, ?string $option = null): array => ['kind' => 'sheet', 'field' => $field, 'op' => 'mark'] + (null === $option ? [] : ['value' => $option]);
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-dice-pools'), 'sceneTypes', []);
        $content = ReleaseArrays::with($content, 'flows', []);
        $content = ReleaseArrays::with($content, 'gameSystem.key', 'pushing');
        $content = ReleaseArrays::with($content, 'sheet', ['fields' => [
            ['key' => 'agility', 'label' => 'Agility', 'type' => 'number', 'min' => 1, 'max' => 5, 'default' => 3],
            ['key' => 'zero', 'label' => 'Zero', 'type' => 'number', 'default' => 1],
            ['key' => 'stress', 'label' => 'Stress', 'type' => 'number', 'min' => 0, 'max' => 10],
            ['key' => 'hp', 'label' => 'Hit points', 'type' => 'track', 'max' => 10],
            ['key' => 'physical', 'label' => 'Physical', 'type' => 'conditions', 'options' => [['key' => 'hurt', 'label' => 'Hurt'], ['key' => 'exhausted', 'label' => 'Exhausted'], ['key' => 'bruised', 'label' => 'Bruised']]],
            ['key' => 'mental', 'label' => 'Mental', 'type' => 'conditions', 'options' => [['key' => 'shaken', 'label' => 'Shaken']]],
        ]]);
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with($content, 'checks', [
            ['key' => 'grit', 'label' => 'Grit', 'hint' => 'Count the fives.', 'pool' => $pool, self::BANDS => $bands, 'push' => ['label' => 'Take a point of stress', 'effects' => [
                ['kind' => 'sheet', 'field' => 'stress', 'op' => 'add', 'value' => 1],
                ['kind' => 'tracker', 'tracker' => 'dread', 'op' => 'add', 'value' => 1],
            ]]],
            ['key' => 'wounded', 'label' => 'Wounded', 'pool' => ['count' => 3] + $pool, self::BANDS => $bands, 'push' => ['label' => 'Take two Conditions', 'effects' => [$mark('physical'), $mark('physical')]]],
            ['key' => 'mixed', 'label' => 'Mixed', 'pool' => ['count' => 3] + $pool, self::BANDS => $bands, 'push' => ['label' => 'Pay in kind', 'effects' => [
                ['kind' => 'sheet', 'field' => 'stress', 'op' => 'add', 'value' => 2],
                ['kind' => 'sheet', 'field' => 'hp', 'op' => 'subtract', 'value' => 3],
                $mark('mental', 'shaken'),
                $mark('physical'),
            ]]],
            ['key' => 'cursed', 'label' => 'Cursed', 'pool' => $pool, self::BANDS => $bands, 'push' => ['label' => 'Pay', 'effects' => [['kind' => 'sheet', 'field' => 'stress', 'op' => 'set', 'value' => ['formula' => '{agility} / ({zero} - 1)']]]]],
            ['key' => 'fragile', 'label' => 'Fragile', 'pool' => $pool, self::BANDS => [['key' => 'low', 'label' => 'Low', 'upTo' => ['formula' => '{agility} / {zero}']], ['key' => 'high', 'label' => 'High']], 'push' => ['label' => 'Pay']],
            ['key' => 'plain', 'label' => 'Plain', 'pool' => $pool, self::BANDS => $bands],
            ['key' => 'knife', 'label' => 'Knife', 'dice' => '1d6', self::BANDS => [['key' => 'only', 'label' => 'Only']]],
        ]);
        $bus = $container->get(CommandBus::class);
        $bus->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9301', $content, false));
        $bus->dispatch(new CreateUser($container->get(UserIdGenerator::class)->generate()->toString(), 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function theCampaignTellsWhichChecksCanBePushedAndWhereThePlayerPicks(): void
    {
        [$id] = $this->campaignInAScene();

        $checks = array_column($this->campaignJson($id)['checks'], 'push', 'key');

        self::assertSame([
            'grit' => ['label' => 'Take a point of stress', 'picks' => []],
            'wounded' => ['label' => 'Take two Conditions', 'picks' => ['physical', 'physical']],
            'mixed' => ['label' => 'Pay in kind', 'picks' => ['physical']],
            'cursed' => ['label' => 'Pay', 'picks' => []],
            'fragile' => ['label' => 'Pay', 'picks' => []],
            'plain' => null,
            'knife' => null,
        ], $checks);
    }

    #[Test]
    public function aPushRerollsOnlyTheFailedDiceKeepsTheSuccessesPicksTheBandAgainAndPaysTheCost(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'grit', [1, 6, 2, 5]);
        self::assertSame(['weak', 2], [$entry['content']['bandKey'], $entry['content']['total']]);

        // Only the dice that failed (the 1 and the 2) are re-rolled, in order.
        $this->random->script(6, 5);
        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(200);
        $pushed = $this->entryJson();
        self::assertSame($entry['id'], $pushed['id']);
        $content = $pushed['content'];
        self::assertSame(['strong', 'Strong', 4, 4], [$content['bandKey'], $content['bandLabel'], $content['total'], $content['diceTotal']]);
        self::assertSame($entry['content']['pool']['dice'], $content['pool']['dice'], 'The first roll stays as it was.');
        self::assertSame(2, $content['pool']['successes']);
        self::assertSame([
            'label' => 'Take a point of stress',
            'dice' => [
                ['group' => 'pool', 'face' => 6, 'success' => true, 'rerolled' => true],
                ['group' => 'pool', 'face' => 6, 'success' => true, 'rerolled' => false],
                ['group' => 'pool', 'face' => 5, 'success' => true, 'rerolled' => true],
                ['group' => 'pool', 'face' => 5, 'success' => true, 'rerolled' => false],
            ],
            'successes' => 4,
            'rolledBandKey' => 'weak',
            'rolledBandLabel' => 'Weak',
            'changes' => [['field' => 'stress', 'before' => 0, 'after' => 1, 'dice' => null]],
            'special' => null,
        ], $content['pool']['push']);
        // The same entry was completed: the journal holds it once, as returned.
        self::assertSame([$pushed], $this->get(\sprintf('/api/campaigns/%s/journal', $id)));
        self::assertSame([1, 1], [$this->value($id, $vi, 'stress'), $this->tracker($id, 'dread')]);
    }

    #[Test]
    public function anUnpushedPoolEntryReadsWithoutAPush(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'grit', [1, 6, 2, 5]);

        self::assertNull($entry['content']['pool']['push']);
        // An entry stored before pushes existed has no push key at all.
        $stored = $this->connection()->fetchOne('SELECT content FROM play_journal_entry WHERE id = ?', [$entry['id']]);
        self::assertIsString($stored);
        $decoded = json_decode($stored, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['pool']);
        self::assertArrayNotHasKey('push', $decoded['pool']);
        self::assertNull($this->journalJson($id)[0]['content']['pool']['push']);
    }

    #[Test]
    public function aCostOfNumbersTracksConditionsAndPicksIsAppliedInOrder(): void
    {
        [$id, $vi] = $this->campaignInAScene(['physical' => ['exhausted']]);
        $entry = $this->roll($id, $vi, 'mixed', [1, 5, 1]);

        $this->random->script(1, 1);
        $this->push($id, $entry['id'], ['picks' => ['bruised']]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            ['field' => 'stress', 'before' => 0, 'after' => 2, 'dice' => null],
            ['field' => 'hp', 'before' => 10, 'after' => 7, 'dice' => null],
            ['field' => 'mental', 'before' => 0, 'after' => 1, 'dice' => null],
            ['field' => 'physical', 'before' => 1, 'after' => 2, 'dice' => null],
        ], $this->entryJson()['content']['pool']['push']['changes'] ?? null);
        $character = $this->character($id, $vi);
        self::assertSame([2, ['current' => 7, 'max' => 10], ['shaken'], ['exhausted', 'bruised']], [$character['values']['stress'], $character['values']['hp'], $character['values']['mental'], $character['values']['physical']]);
    }

    #[Test]
    public function aMarkWithoutAnOptionOnAFullyMarkedFieldTakesNoPickAndMarksNothing(): void
    {
        [$id, $vi] = $this->campaignInAScene(['physical' => ['hurt', 'exhausted', 'bruised']]);
        $entry = $this->roll($id, $vi, 'wounded', [1, 1, 1]);

        $this->random->script(5, 5, 5);
        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            ['field' => 'physical', 'before' => 3, 'after' => 3, 'dice' => null],
            ['field' => 'physical', 'before' => 3, 'after' => 3, 'dice' => null],
        ], $this->entryJson()['content']['pool']['push']['changes'] ?? null);
        self::assertSame(['hurt', 'exhausted', 'bruised'], $this->value($id, $vi, 'physical'));
    }

    #[Test]
    public function twoPicksOfOneFieldEachTakeAnOptionStillUnmarkedAtThatPoint(): void
    {
        [$id, $vi] = $this->campaignInAScene(['physical' => ['hurt']]);
        $entry = $this->roll($id, $vi, 'wounded', [1, 1, 1]);

        $this->random->script(5, 5, 5);
        $this->push($id, $entry['id'], ['picks' => ['bruised', 'exhausted']]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['hurt', 'exhausted', 'bruised'], $this->value($id, $vi, 'physical'));
    }

    /**
     * @return iterable<string, array{string, list<string>, array<string, mixed>, string}> the check, the marked physical conditions, the body and the message
     */
    public static function badPicks(): iterable
    {
        yield 'a pick missing' => ['wounded', [], ['picks' => ['hurt']], 'The cost of the push marks an option of "physical": pick one.'];
        yield 'no pick at all' => ['wounded', [], [], 'The cost of the push marks an option of "physical": pick one.'];
        yield 'an unknown option' => ['wounded', [], ['picks' => ['ghost', 'hurt']], '"ghost" is not an unmarked option of "physical" (unmarked: hurt, exhausted, bruised).'];
        yield 'an option already marked' => ['wounded', ['hurt'], ['picks' => ['hurt', 'bruised']], '"hurt" is not an unmarked option of "physical" (unmarked: exhausted, bruised).'];
        yield 'the same option twice' => ['wounded', [], ['picks' => ['hurt', 'hurt']], '"hurt" is not an unmarked option of "physical" (unmarked: exhausted, bruised).'];
        yield 'an option of another field' => ['mixed', [], ['picks' => ['shaken']], '"shaken" is not an unmarked option of "physical" (unmarked: hurt, exhausted, bruised).'];
        yield 'a pick too many' => ['mixed', [], ['picks' => ['hurt', 'bruised']], 'The cost of the push takes no more picks (it picks among physical); unused: bruised.'];
        yield 'a pick for a cost without any' => ['grit', [], ['picks' => ['hurt']], 'The cost of the push takes no more picks (it picks among nothing); unused: hurt.'];
        yield 'a pick too many for a full field' => ['wounded', ['hurt', 'exhausted', 'bruised'], ['picks' => ['hurt']], 'The cost of the push takes no more picks (it picks among physical, physical); unused: hurt.'];
    }

    /**
     * @param list<string>         $marked
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('badPicks')]
    public function picksThatDoNotFitTheCostAreRefusedAndNothingChanges(string $check, array $marked, array $body, string $message): void
    {
        [$id, $vi] = $this->campaignInAScene(['physical' => $marked]);
        $entry = $this->roll($id, $vi, $check, [1, 1, 1, 1]);
        $before = $this->state($id);

        $this->random->script(5, 5, 5, 5);
        $this->push($id, $entry['id'], $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => $message], $this->json());
        self::assertSame($before, $this->state($id));
    }

    #[Test]
    public function aCostThatCannotBeEvaluatedIsRefusedAndNothingChanges(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'cursed', [1, 5, 1, 5]);
        $before = $this->state($id);

        $this->random->script(5, 5);
        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('cannot be evaluated', $this->errorMessage());
        self::assertSame($before, $this->state($id));
    }

    #[Test]
    public function aBandThatCannotBeEvaluatedAnyMoreIsRefusedAndRerollsNothing(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'fragile', [1, 5, 1, 5]);
        $this->updateCharacter($id, $vi, ['zero' => 0]);
        $before = $this->state($id);

        // No number is scripted: a die re-rolled before the bands were evaluated would fail the test.
        $this->random->script();
        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Check "fragile" cannot be performed', $this->errorMessage());
        self::assertSame($before, $this->state($id));
    }

    /**
     * @return iterable<string, array{mixed}> the body sent
     */
    public static function malformedBodies(): iterable
    {
        yield 'picks that is a string' => [['picks' => 'hurt']];
        yield 'picks that is an object' => [['picks' => ['a' => 'hurt']]];
        yield 'a number among the picks' => [['picks' => ['hurt', 1]]];
        yield 'a body that is a list' => [['hurt']];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function malformedPicksAreABadRequestAndNothingChanges(mixed $body): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'grit', [1, 1, 1, 1]);
        $before = $this->state($id);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/%s/push', $id, $entry['id']), \is_array($body) ? $body : []);
        if (['hurt'] === $body) {
            $this->client->request('POST', \sprintf('/api/campaigns/%s/journal/%s/push', $id, $entry['id']), server: ['CONTENT_TYPE' => 'application/json'], content: '["hurt"]');
        }

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => 'Send no body, or a JSON object with an optional list of strings "picks", such as {"picks": ["hurt"]}.'], $this->json());
        self::assertSame($before, $this->state($id));
    }

    #[Test]
    public function aBodyThatIsNotJsonIsUnsupportedAndOneThatIsBrokenIsABadRequest(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'grit', [1, 1, 1, 1]);
        $uri = \sprintf('/api/campaigns/%s/journal/%s/push', $id, $entry['id']);
        $before = $this->state($id);

        $this->client->request('POST', $uri, server: ['CONTENT_TYPE' => 'text/plain'], content: 'push');
        self::assertResponseStatusCodeSame(415);
        self::assertSame(['error' => 'Send the push as JSON, or without a body.'], $this->json());

        $this->client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json'], content: '{"picks": [');
        self::assertResponseStatusCodeSame(400);
        self::assertSame($before, $this->state($id));
    }

    #[Test]
    public function aPushNeedsNoBodyAndANullPicksIsNoPicks(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'grit', [1, 5, 1, 5]);

        $this->random->script(5, 5);
        $this->client->request('POST', \sprintf('/api/campaigns/%s/journal/%s/push', $id, $entry['id']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(4, $this->entryJson()['content']['total']);

        // A null "picks" reads like an absent one.
        $second = $this->roll($id, $vi, 'grit', [1, 5, 1, 5]);
        $this->random->script(5, 5);
        $this->push($id, $second['id'], ['picks' => null]);

        self::assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function aCampaignOrEntryThatIsNotThePlayersIsNotFound(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        [$other, $otherVi] = $this->campaignInAScene();
        $mine = $this->roll($id, $vi, 'grit', [1, 1, 1, 1]);
        $theirs = $this->roll($other, $otherVi, 'grit', [1, 1, 1, 1]);
        $before = [$this->state($id), $this->state($other)];

        foreach ([
            [$id, self::ENTRY_ID_PREFIX.'99', 'No entry has this id.'],
            [$id, 'not-an-id', 'An id that is no UUID.'],
            [$id, $theirs['id'], 'An entry of another campaign.'],
            ['0199a1b2-c3d4-7e5f-8a9b-0c1d2e3fffff', $mine['id'], 'An unknown campaign.'],
            ['not-an-id', $mine['id'], 'A campaign id that is no UUID.'],
        ] as [$campaign, $entry, $why]) {
            $this->push($campaign, $entry);

            self::assertResponseStatusCodeSame(404, $why);
        }

        self::assertSame($before, [$this->state($id), $this->state($other)]);
    }

    #[Test]
    public function anUnknownEntryIsNotFoundEvenWhenThePinnedReleaseCannotBeRead(): void
    {
        [$id] = $this->campaignInAScene();
        $this->connection()->executeStatement('UPDATE studio_gamesystem_release SET schema_version = 99');

        $this->push($id, self::ENTRY_ID_PREFIX.'99');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function anotherPlayersCampaignIsNotFoundEither(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'grit', [1, 1, 1, 1]);
        $this->client->jsonRequest('POST', '/api/auth/logout');
        $container = self::getContainer();
        $container->get(CommandBus::class)->dispatch(new CreateUser($container->get(UserIdGenerator::class)->generate()->toString(), 'bob@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'bob@example.com', 'password' => self::PASSWORD]);

        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function aRollIsPushedOnce(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'grit', [1, 5, 1, 5]);
        $this->random->script(1, 1);
        $this->push($id, $entry['id']);
        self::assertResponseStatusCodeSame(200);
        $before = $this->state($id);

        $this->random->script(5, 5);
        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => \sprintf('The check of journal entry "%s" was pushed already, and a roll is pushed once.', $entry['id'])], $this->json());
        self::assertSame($before, $this->state($id));
    }

    #[Test]
    public function onlyTheLatestEntryOfTheJournalCanBePushed(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'grit', [1, 5, 1, 5]);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/notes', $id), ['text' => 'Something happened.']);
        self::assertResponseStatusCodeSame(201);
        $before = $this->state($id);

        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Only the latest entry of the journal can be pushed.'], $this->json());
        self::assertSame($before, $this->state($id));
    }

    #[Test]
    public function aRollWithoutAFailedDieCannotBePushed(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'grit', [5, 6, 5, 6]);
        $before = $this->state($id);

        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'No regular die of the roll failed: there is nothing to re-roll.'], $this->json());
        self::assertSame($before, $this->state($id));
    }

    #[Test]
    public function aPlainNoteOrADiceCheckCannotBePushed(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $this->random->script(4);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $id), ['check' => 'knife', 'character' => $vi]);
        self::assertResponseStatusCodeSame(201);
        $dice = $this->entryJson()['id'];
        $this->push($id, $dice);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Only the roll of a pool check can be pushed.'], $this->json());

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/notes', $id), ['text' => 'A note.']);
        $this->push($id, $this->entryJson()['id']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Only the roll of a pool check can be pushed.'], $this->json());
    }

    #[Test]
    public function aRollRecordedByAFlowStepCannotBePushedFromTheSheet(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'grit', [1, 5, 1, 5]);
        $this->connection()->executeStatement('UPDATE play_journal_entry SET flow_step = ?::jsonb WHERE id = ?', [json_encode(['key' => 'shock', 'title' => 'Shock', 'prompt' => null], \JSON_THROW_ON_ERROR), $entry['id']]);
        $before = $this->state($id);

        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'A roll recorded by a Flow step cannot be pushed from the sheet.'], $this->json());
        self::assertSame($before, $this->state($id));
    }

    /**
     * @return iterable<string, array{string, string}> the stored change (a SQL expression over content) and the check it names
     */
    public static function changedChecks(): iterable
    {
        yield 'a check the release dropped' => ["jsonb_set(content, '{checkKey}', '\"ghost\"')", 'ghost'];
        yield 'a check without a push' => ["jsonb_set(content, '{checkKey}', '\"plain\"')", 'plain'];
        yield 'a check that rolls dice now' => ["jsonb_set(content, '{checkKey}', '\"knife\"')", 'knife'];
        yield 'a pool with other sides' => ["jsonb_set(jsonb_set(content, '{pool,sides}', '8'), '{expression}', '\"4d8\"')", 'grit'];
    }

    #[Test]
    #[DataProvider('changedChecks')]
    public function aRollOfACheckTheReleaseNoLongerDeclaresWithThatPoolAndAPushIsRefused(string $change, string $key): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'grit', [1, 5, 1, 5]);
        $this->connection()->executeStatement(\sprintf('UPDATE play_journal_entry SET content = %s WHERE id = ?', $change), [$entry['id']]);
        $before = $this->state($id);

        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => \sprintf('The pinned release has no pushable pool check "%s" with the pool that was rolled.', $key)], $this->json());
        self::assertSame($before, $this->state($id));
    }

    #[Test]
    public function aCharacterWhoLeftTheCampaignCannotPayTheCost(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'grit', [1, 5, 1, 5]);
        $gone = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3fa001';
        $this->connection()->executeStatement('UPDATE play_journal_entry SET content = jsonb_set(content, ?, ?::jsonb) WHERE id = ?', ['{characterId}', json_encode($gone, \JSON_THROW_ON_ERROR), $entry['id']]);
        $before = $this->state($id);

        $this->push($id, $entry['id']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => \sprintf('The Character "%s" who rolled is no longer in the campaign.', $gone)], $this->json());
        self::assertSame($before, $this->state($id));
    }

    #[Test]
    public function aPushWhileAnotherRequestSavesTheCampaignIsAConflictAndKeepsNothing(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'grit', [1, 5, 1, 5]);
        $before = $this->state($id);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $concurrentSave = new readonly class($this->connection(), $id) {
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
            $this->random->script(5, 5);
            $this->push($id, $entry['id']);
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentSave);
        }

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => \sprintf('Campaign "%s" was changed by another request. Reload it and try again.', $id)], $this->json());
        self::assertSame($before, $this->state($id));
    }

    #[Test]
    public function aPushThatAnEntryRecordedMeanwhileOvertakesIsAConflictAndKeepsNothing(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $entry = $this->roll($id, $vi, 'grit', [1, 5, 1, 5]);
        $before = $this->state($id);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        // Another request records an entry after the handler read the latest one and before the push is kept (once, on the first flush).
        $concurrentRecord = new readonly class($this->connection(), $entry['id']) {
            public function __construct(private Connection $connection, private string $entryId)
            {
            }

            public function preFlush(): void
            {
                $this->connection->executeStatement(
                    "INSERT INTO play_journal_entry (id, campaign_id, session_number, scene_number, recorded_at, content, flow_step) SELECT '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f80ff', campaign_id, session_number, scene_number, recorded_at + interval '1 second', '{\"kind\": \"note\", \"text\": \"Meanwhile.\"}'::jsonb, NULL FROM play_journal_entry WHERE id = ? ON CONFLICT (id) DO NOTHING",
                    [$this->entryId],
                );
            }
        };
        $entityManager->getEventManager()->addEventListener([Events::preFlush], $concurrentRecord);

        try {
            $this->random->script(5, 5);
            $this->push($id, $entry['id']);
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentRecord);
        }

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Only the latest entry of the journal can be pushed.'], $this->json());
        self::assertSame($before, $this->state($id));
    }

    /**
     * @param array<string, mixed> $values the Character's values besides the defaults
     *
     * @return array{string, string} the campaign in session 1, scene 1, and Vi (agility 3) in it
     */
    private function campaignInAScene(array $values = []): array
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => 'pushing']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->entryJson()['id'];
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $id));
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes', $id), ['title' => 'At the gate']);
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi', 'values' => $values]);
        self::assertResponseStatusCodeSame(201);

        return [$id, $this->entryJson()['id']];
    }

    /**
     * @param list<int> $faces the dice of the roll
     *
     * @return Entry the entry the roll recorded
     */
    private function roll(string $campaignId, string $character, string $check, array $faces): array
    {
        $this->random->script(...$faces);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $campaignId), ['check' => $check, 'character' => $character]);
        self::assertResponseStatusCodeSame(201);

        return $this->entryJson();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function push(string $campaignId, string $entryId, array $body = []): void
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/%s/push', $campaignId, $entryId), $body);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function updateCharacter(string $campaignId, string $characterId, array $values): void
    {
        $character = $this->character($campaignId, $characterId);
        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $campaignId, $characterId), ['name' => 'Vi', 'values' => $values + $character['values']]);
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * Everything a push can change, as the API shows it: the characters, the Trackers and the journal.
     *
     * @return array{list<Character>, array<string, int>, list<Entry>}
     */
    private function state(string $campaignId): array
    {
        $campaign = $this->campaignJson($campaignId);

        return [$campaign['characters'], array_column($campaign['trackers'], 'value', 'key'), $this->journalJson($campaignId)];
    }

    /**
     * @return Character
     */
    private function character(string $campaignId, string $characterId): array
    {
        foreach ($this->campaignJson($campaignId)['characters'] as $character) {
            if ($character['id'] === $characterId) {
                return $character;
            }
        }

        self::fail('No such Character.');
    }

    private function value(string $campaignId, string $characterId, string $field): mixed
    {
        return $this->character($campaignId, $characterId)['values'][$field] ?? null;
    }

    private function tracker(string $campaignId, string $key): mixed
    {
        return array_column($this->campaignJson($campaignId)['trackers'], 'value', 'key')[$key] ?? null;
    }

    /**
     * @return Entry
     */
    private function entryJson(): array
    {
        /** @var Entry $entry */
        $entry = $this->json();

        return $entry;
    }

    private function errorMessage(): string
    {
        $error = $this->json()['error'] ?? null;
        self::assertIsString($error);

        return $error;
    }

    /**
     * @return Campaign
     */
    private function campaignJson(string $campaignId): array
    {
        /** @var Campaign $campaign */
        $campaign = $this->get('/api/campaigns/'.$campaignId);

        return $campaign;
    }

    /**
     * @return list<Entry>
     */
    private function journalJson(string $campaignId): array
    {
        /** @var list<Entry> $journal */
        $journal = $this->get(\sprintf('/api/campaigns/%s/journal', $campaignId));

        return $journal;
    }

    /**
     * @return array<mixed>
     */
    private function get(string $uri): array
    {
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    /**
     * Impure: it decodes the latest response, so PHPStan must not reuse a type narrowed by an
     * assertion on an earlier one.
     *
     * @return array<mixed>
     *
     * @phpstan-impure
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
