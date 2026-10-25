<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\Clock;
use App\Play\Application\JournalEntryIdGenerator;
use App\Play\Infrastructure\Http\CheckContentResponse;
use App\Play\Infrastructure\Http\CheckDifficultyResponse;
use App\Play\Infrastructure\Http\CheckResponse;
use App\Play\Infrastructure\Http\JournalController;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Play\RescriptableRandomNumberGenerator;
use App\Tests\Support\Play\UuidSequenceJournalEntryIdGenerator;
use App\Tests\Support\Studio\ReleaseArrays;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Checks over the Play API (ADR 0024), on the release "valid/v2-sheet" without its Scene Types and
 * with the checks "skill" (1d10 + reflexes + luck against a Difficulty Value of 13, 9 to 29: "fail"
 * up to difficulty - 1, "success" up to difficulty + 4, "crit"), "luck" (d%, no modifier or
 * difficulty: "bad" up to 20, "good") and "broken" (reflexes / debt, which divides by zero while the
 * debt is 0). Vi (reflexes 7, luck -2: a skill modifier of 5) joins before the session and is not in
 * the scene; Ada (reflexes 4, luck 0: a modifier of 4) joins during it and is.
 */
#[CoversClass(JournalController::class)]
#[CoversClass(CheckContentResponse::class)]
#[CoversClass(CheckResponse::class)]
#[CoversClass(CheckDifficultyResponse::class)]
final class JournalChecksApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string ENTRY_ID_PREFIX = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f80';

    private KernelBrowser $client;
    private UuidSequenceJournalEntryIdGenerator $entryIds;
    private RescriptableRandomNumberGenerator $random;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $clock = new FixedClock('2026-10-14T09:00:00+00:00');
        $container->set(Clock::class, $clock);
        $this->entryIds = new UuidSequenceJournalEntryIdGenerator(self::ENTRY_ID_PREFIX);
        $container->set(JournalEntryIdGenerator::class, $this->entryIds);
        $this->random = new RescriptableRandomNumberGenerator();
        $container->set(RandomNumberGenerator::class, $this->random);

        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-sheet'), 'sceneTypes', []);
        $content = ReleaseArrays::with($content, 'gameSystem.key', 'rolling');
        /** @var array<string, mixed> $content */
        $content = ReleaseArrays::with($content, 'checks', [
            [
                'key' => 'skill',
                'label' => 'Skill check',
                'hint' => 'A stat against a Difficulty Value.',
                'dice' => '1d10',
                'modifier' => '{reflexes} + {luck}',
                'difficulty' => ['label' => 'Difficulty Value', 'default' => 13, 'min' => 9, 'max' => 29],
                'bands' => [
                    ['key' => 'fail', 'label' => 'Fail', 'upTo' => ['formula' => '{check:difficulty} - 1']],
                    ['key' => 'success', 'label' => 'Success', 'upTo' => ['formula' => '{check:difficulty} + 4']],
                    ['key' => 'crit', 'label' => 'Critical success'],
                ],
            ],
            ['key' => 'luck', 'label' => 'Luck', 'dice' => 'd%', 'bands' => [['key' => 'bad', 'label' => 'Bad', 'upTo' => 20], ['key' => 'good', 'label' => 'Good']]],
            ['key' => 'broken', 'label' => 'Broken', 'dice' => '1d6', 'modifier' => '{reflexes} / {debt}', 'bands' => [['key' => 'only', 'label' => 'Only']]],
            ['key' => 'grit', 'label' => 'Grit', 'hint' => 'Count the sixes.', 'pool' => ['count' => ['formula' => '{reflexes} + {luck}'], 'sides' => 6, 'successAt' => 5], 'bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => 0], ['key' => 'hit', 'label' => 'Hit']]],
            ['key' => 'hands', 'label' => 'Hands', 'pool' => ['count' => 2, 'sides' => 10, 'successAt' => 8], 'bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => 0], ['key' => 'hit', 'label' => 'Hit']]],
            ['key' => 'slip', 'label' => 'Slip', 'pool' => ['count' => ['formula' => '{reflexes} / {debt}'], 'sides' => 6, 'successAt' => 6], 'bands' => [['key' => 'only', 'label' => 'Only']]],
        ]);
        $bus = $container->get(CommandBus::class);
        $bus->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9201', $content, false));
        /** @var array<string, mixed> $plain */
        $plain = ReleaseArrays::with(ReleaseArrays::with($content, 'gameSystem.key', 'plain'), 'checks', []);
        $bus->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9202', $plain, false));
        $bus->dispatch(new CreateUser($container->get(UserIdGenerator::class)->generate()->toString(), 'ada@example.com', self::PASSWORD, ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function theCampaignExposesTheChecksOfItsPinnedReleaseWithoutTheirBands(): void
    {
        $campaign = $this->campaign($this->createCampaign());

        self::assertSame([
            [
                'key' => 'skill',
                'label' => 'Skill check',
                'hint' => 'A stat against a Difficulty Value.',
                'dice' => '1d10',
                'pool' => null,
                'difficulty' => ['label' => 'Difficulty Value', 'default' => 13, 'min' => 9, 'max' => 29],
            ],
            ['key' => 'luck', 'label' => 'Luck', 'hint' => null, 'dice' => '1d100', 'pool' => null, 'difficulty' => null],
            ['key' => 'broken', 'label' => 'Broken', 'hint' => null, 'dice' => '1d6', 'pool' => null, 'difficulty' => null],
            ['key' => 'grit', 'label' => 'Grit', 'hint' => 'Count the sixes.', 'dice' => null, 'pool' => ['count' => null, 'countFormula' => '{reflexes} + {luck}', 'countText' => 'Reflexes + Luck', 'sides' => 6, 'successAt' => 5], 'difficulty' => null],
            ['key' => 'hands', 'label' => 'Hands', 'hint' => null, 'dice' => null, 'pool' => ['count' => 2, 'countFormula' => null, 'countText' => null, 'sides' => 10, 'successAt' => 8], 'difficulty' => null],
            ['key' => 'slip', 'label' => 'Slip', 'hint' => null, 'dice' => null, 'pool' => ['count' => null, 'countFormula' => '{reflexes} / {debt}', 'countText' => 'Reflexes / Debt', 'sides' => 6, 'successAt' => 6], 'difficulty' => null],
        ], $campaign['checks'] ?? null);
    }

    #[Test]
    public function aReleaseWithoutChecksExposesAnEmptyList(): void
    {
        $campaign = $this->campaign($this->createCampaign('plain'));

        self::assertSame([], $campaign['checks'] ?? null);
    }

    #[Test]
    public function aCharacterOutsideTheScenePerformsACheckWithADifficultyAndASituationalModifier(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $this->random->script(9);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $id), ['check' => 'skill', 'character' => $vi, 'difficulty' => 15, 'situational' => 2]);

        self::assertResponseStatusCodeSame(201);
        $created = [
            'id' => self::ENTRY_ID_PREFIX.'01',
            'sessionNumber' => 1,
            'sceneNumber' => 1,
            'recordedAt' => '2026-10-14T09:00:00+00:00',
            'kind' => 'check',
            'flowStep' => null,
            'content' => [
                'kind' => 'check',
                'characterId' => $vi,
                'characterName' => 'Vi',
                'checkKey' => 'skill',
                'checkLabel' => 'Skill check',
                'expression' => '1d10',
                'groups' => [['notation' => '1d10', 'sides' => 10, 'dice' => [['value' => 9, 'kept' => true]], 'subtotal' => 9]],
                'diceTotal' => 9,
                'modifier' => 5,
                'situational' => 2,
                'difficulty' => 15,
                'total' => 16,
                'bandKey' => 'success',
                'bandLabel' => 'Success',
                'hint' => 'A stat against a Difficulty Value.',
                'modifierFormula' => '{reflexes} + {luck}',
                'modifierTerms' => [
                    ['reference' => '{reflexes}', 'label' => 'Reflexes', 'value' => 7],
                    ['reference' => '{luck}', 'label' => 'Luck', 'value' => -2],
                ],
                'pool' => null,
            ],
        ];
        self::assertJsonStringEqualsJsonString(json_encode($created, \JSON_THROW_ON_ERROR), (string) $this->client->getResponse()->getContent());
        // What was stored reads back as the same entry.
        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', $id));
        self::assertJsonStringEqualsJsonString(json_encode([$created], \JSON_THROW_ON_ERROR), (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function aCharacterPerformsAPoolCheckSizedFromTheSheetAndExtraDice(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        // Reflexes 7 + luck -2 = 5 dice, one extra die: six dice, two succeed.
        $this->random->script(1, 5, 6, 2, 4, 3);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $id), ['check' => 'grit', 'character' => $vi, 'situational' => 1]);

        self::assertResponseStatusCodeSame(201);
        $die = static fn (int $face): array => ['group' => 'pool', 'face' => $face, 'success' => $face >= 5];
        $created = [
            'id' => self::ENTRY_ID_PREFIX.'01',
            'sessionNumber' => 1,
            'sceneNumber' => 1,
            'recordedAt' => '2026-10-14T09:00:00+00:00',
            'kind' => 'check',
            'flowStep' => null,
            'content' => [
                'kind' => 'check',
                'characterId' => $vi,
                'characterName' => 'Vi',
                'checkKey' => 'grit',
                'checkLabel' => 'Grit',
                'expression' => '6d6',
                'groups' => [],
                'diceTotal' => 2,
                'modifier' => 0,
                'situational' => 1,
                'difficulty' => null,
                'total' => 2,
                'bandKey' => 'hit',
                'bandLabel' => 'Hit',
                'hint' => 'Count the sixes.',
                'modifierFormula' => null,
                'modifierTerms' => [],
                'pool' => [
                    'count' => 5,
                    'countFormula' => '{reflexes} + {luck}',
                    'terms' => [
                        ['reference' => '{reflexes}', 'label' => 'Reflexes', 'value' => 7],
                        ['reference' => '{luck}', 'label' => 'Luck', 'value' => -2],
                    ],
                    'size' => 6,
                    'sides' => 6,
                    'successAt' => 5,
                    'dice' => [$die(1), $die(5), $die(6), $die(2), $die(4), $die(3)],
                    'successes' => 2,
                ],
            ],
        ];
        self::assertJsonStringEqualsJsonString(json_encode($created, \JSON_THROW_ON_ERROR), (string) $this->client->getResponse()->getContent());
        // What was stored reads back as the same entry.
        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', $id));
        self::assertJsonStringEqualsJsonString(json_encode([$created], \JSON_THROW_ON_ERROR), (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function aPoolCheckWithANumberCountRecordsNoFormulaAndKeepsAtLeastOneDie(): void
    {
        [$id, , $ada] = $this->campaignInAScene();
        $this->random->script(9);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $id), ['check' => 'hands', 'character' => $ada, 'situational' => -50]);

        self::assertResponseStatusCodeSame(201);
        $content = $this->json()['content'] ?? null;
        self::assertIsArray($content);
        $pool = $content['pool'] ?? null;
        self::assertIsArray($pool);
        self::assertArrayHasKey('countFormula', $pool);
        self::assertSame([2, null, [], 1, 1, 1, 'hit'], [$pool['count'] ?? null, $pool['countFormula'], $pool['terms'] ?? null, $pool['size'] ?? null, $pool['successes'] ?? null, $content['total'] ?? null, $content['bandKey'] ?? null]);
    }

    /**
     * A row stored before pools has no pool part (a dice check): it reads as before, with no pool.
     */
    #[Test]
    public function anEntryStoredWithoutAPoolPartReadsWithNoPool(): void
    {
        [$id, , $ada] = $this->campaignInAScene();
        $this->random->script(8);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $id), ['check' => 'skill', 'character' => $ada]);
        self::assertResponseStatusCodeSame(201);
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(0, $connection->fetchOne("SELECT count(*) FROM play_journal_entry WHERE jsonb_exists(content, 'pool')"), 'A dice check stores no pool part.');

        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', $id));

        $entry = $this->json()[0] ?? null;
        self::assertIsArray($entry);
        $content = $entry['content'] ?? null;
        self::assertIsArray($content);
        self::assertSame([4, null], [$content['modifier'] ?? null, \array_key_exists('pool', $content) ? $content['pool'] : 'absent']);
    }

    #[Test]
    public function aNoteAttachesACheckResult(): void
    {
        [$id, $vi] = $this->campaignInAScene();
        $this->random->script(9);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $id), ['check' => 'skill', 'character' => $vi]);
        self::assertResponseStatusCodeSame(201);
        $check = self::ENTRY_ID_PREFIX.'01';

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/notes', $id), ['text' => 'Vi makes it.', 'attachments' => [$check]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['kind' => 'note', 'text' => 'Vi makes it.', 'attachments' => [$check]], $this->json()['content'] ?? null);
        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', $id));
        $note = $this->json()[1] ?? null;
        self::assertIsArray($note);
        $content = $note['content'] ?? null;
        self::assertIsArray($content);
        self::assertSame([$check], $content['attachments'] ?? null);
    }

    #[Test]
    public function theDefaultDifficultyAndNoSituationalModifierApplyWhenOmittedOrNull(): void
    {
        [$id, , $ada] = $this->campaignInAScene();

        foreach ([['check' => 'skill', 'character' => $ada], ['check' => 'skill', 'character' => $ada, 'difficulty' => null, 'situational' => null]] as $body) {
            $this->random->script(8);
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $id), $body);

            self::assertResponseStatusCodeSame(201);
            $content = $this->json()['content'] ?? null;
            self::assertIsArray($content);
            self::assertSame([4, 0, 13, 12, 'fail', 'Ada'], [$content['modifier'] ?? null, $content['situational'] ?? null, $content['difficulty'] ?? null, $content['total'] ?? null, $content['bandKey'] ?? null, $content['characterName'] ?? null]);
        }
    }

    #[Test]
    public function aCheckWithoutADifficultyOrModifierRecordsNoDifficultyAndAZeroModifier(): void
    {
        [$id, , $ada] = $this->campaignInAScene();
        $this->random->script(77);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $id), ['check' => 'luck', 'character' => $ada, 'situational' => -5]);

        self::assertResponseStatusCodeSame(201);
        $content = $this->json()['content'] ?? null;
        self::assertIsArray($content);
        self::assertSame(['1d100', 77, 0, -5, null, 72, 'good'], [$content['expression'] ?? null, $content['diceTotal'] ?? null, $content['modifier'] ?? null, $content['situational'] ?? null, $content['difficulty'] ?? null, $content['total'] ?? null, $content['bandKey'] ?? null]);
        self::assertSame(['hint' => null, 'modifierFormula' => null, 'modifierTerms' => []], array_intersect_key($content, array_flip(['hint', 'modifierFormula', 'modifierTerms'])));
    }

    /**
     * A row stored before the breakdown was recorded has none of the three keys.
     */
    #[Test]
    public function anEntryStoredBeforeTheBreakdownReadsWithoutOne(): void
    {
        [$id, , $ada] = $this->campaignInAScene();
        $this->random->script(8);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $id), ['check' => 'skill', 'character' => $ada]);
        self::assertResponseStatusCodeSame(201);
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("UPDATE play_journal_entry SET content = content - 'hint' - 'modifierFormula' - 'modifierTerms'");

        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', $id));

        self::assertResponseIsSuccessful();
        $entry = $this->json()[0] ?? null;
        self::assertIsArray($entry);
        $content = $entry['content'] ?? null;
        self::assertIsArray($content);
        self::assertSame(4, $content['modifier'] ?? null);
        self::assertSame(['hint' => null, 'modifierFormula' => null, 'modifierTerms' => []], array_intersect_key($content, array_flip(['hint', 'modifierFormula', 'modifierTerms'])));
    }

    #[Test]
    public function theChecksOfThePinnedReleaseAreUsedNotANewerOne(): void
    {
        [$id, , $ada] = $this->campaignInAScene();
        $newer = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-sheet'), 'sceneTypes', []);
        $newer = ReleaseArrays::with($newer, 'gameSystem.key', 'rolling');
        /** @var array<string, mixed> $newer */
        $newer = ReleaseArrays::with($newer, 'checks', []);
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9203', $newer, false));
        $this->random->script(5);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $id), ['check' => 'skill', 'character' => $ada]);

        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function unprocessableChecks(): iterable
    {
        yield 'an unknown check' => [['check' => 'ghost', 'character' => '%ada%'], 'GameSystem "rolling" v1 has no check "ghost".'];
        yield 'an unknown Character' => [['check' => 'skill', 'character' => 'nobody'], 'Character "nobody" not found.'];
        yield 'a blank Character' => [['check' => 'skill', 'character' => ' '], 'A Character id must not be blank.'];
        yield 'a difficulty for a check without one' => [['check' => 'luck', 'character' => '%ada%', 'difficulty' => 5], 'Check "luck" declares no difficulty.'];
        yield 'a difficulty under the min' => [['check' => 'skill', 'character' => '%ada%', 'difficulty' => 8], 'Difficulty 8 is outside 9 to 29 of check "skill".'];
        yield 'a difficulty over the max' => [['check' => 'skill', 'character' => '%ada%', 'difficulty' => 30], 'Difficulty 30 is outside 9 to 29 of check "skill".'];
        yield 'a situational modifier under -100' => [['check' => 'skill', 'character' => '%ada%', 'situational' => -101], 'The situational modifier must be between -100 and 100, -101 given.'];
        yield 'a situational modifier over 100' => [['check' => 'skill', 'character' => '%ada%', 'situational' => 101], 'The situational modifier must be between -100 and 100, 101 given.'];
        yield 'a modifier that cannot be evaluated' => [['check' => 'broken', 'character' => '%ada%'], 'Check "broken" cannot be performed: The formula divides by zero.'];
        yield 'a pool count that cannot be evaluated' => [['check' => 'slip', 'character' => '%ada%'], 'Check "slip" cannot be performed: The formula divides by zero.'];
        yield 'a situational modifier over 100 on a pool' => [['check' => 'grit', 'character' => '%ada%', 'situational' => 101], 'The situational modifier must be between -100 and 100, 101 given.'];
        yield 'a difficulty for a pool check without one' => [['check' => 'hands', 'character' => '%ada%', 'difficulty' => 2], 'Check "hands" declares no difficulty.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('unprocessableChecks')]
    public function aCheckThatCannotBePerformedIsUnprocessableAndRecordsNothing(array $body, string $error): void
    {
        [$id, , $ada] = $this->campaignInAScene();
        $this->random->script(5, 5);
        $body = array_map(static fn (mixed $value): mixed => '%ada%' === $value ? $ada : $value, $body);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $id), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => $error], $this->json());
        $this->assertJournalIsEmpty($id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'invalid JSON' => ['{"check": '];
        yield 'a list' => ['["skill"]'];
        yield 'a string' => ['"skill"'];
        yield 'no check' => ['{"character": "%ada%"}'];
        yield 'a check that is not a string' => ['{"check": 5, "character": "%ada%"}'];
        yield 'no character' => ['{"check": "skill"}'];
        yield 'a character that is not a string' => ['{"check": "skill", "character": 7}'];
        yield 'a fractional difficulty' => ['{"check": "skill", "character": "%ada%", "difficulty": 15.5}'];
        yield 'a difficulty that is a string' => ['{"check": "skill", "character": "%ada%", "difficulty": "15"}'];
        yield 'a difficulty that is a bool' => ['{"check": "skill", "character": "%ada%", "difficulty": true}'];
        yield 'a fractional situational modifier' => ['{"check": "skill", "character": "%ada%", "situational": 1.5}'];
        yield 'a situational modifier that is a string' => ['{"check": "skill", "character": "%ada%", "situational": "2"}'];
        yield 'a situational modifier that is a bool' => ['{"check": "skill", "character": "%ada%", "situational": false}'];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsABadRequestAndRecordsNothing(string $body): void
    {
        [$id, , $ada] = $this->campaignInAScene();

        $this->client->request('POST', \sprintf('/api/campaigns/%s/journal/checks', $id), server: ['CONTENT_TYPE' => 'application/json'], content: str_replace('%ada%', $ada, $body));

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => 'Send a JSON object with a string "check" and a string "character", and optionally an integer "difficulty" and an integer "situational", such as {"check": "skill", "character": "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9001", "difficulty": 15, "situational": -2}.'], $this->json());
        $this->assertJournalIsEmpty($id);
    }

    #[Test]
    public function aCheckNeedsAScene(): void
    {
        $id = $this->createCampaign();
        $ada = $this->createCharacter($id, 'Ada', []);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $id));
        $this->random->script(5);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $id), ['check' => 'skill', 'character' => $ada]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Start a scene before recording a journal entry.'], $this->json());
        $this->assertJournalIsEmpty($id);
    }

    #[Test]
    public function anEntryIdAlreadyTakenIsAConflict(): void
    {
        [$id, , $ada] = $this->campaignInAScene();
        $this->entryIds->repeat('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8099');
        $this->random->script(5, 5);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $id), ['check' => 'skill', 'character' => $ada]);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/journal/checks', $id), ['check' => 'skill', 'character' => $ada]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'A journal entry with id "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f8099" already exists.'], $this->json());
        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', $id));
        self::assertCount(1, $this->json());
    }

    /**
     * @return array{string, string, string} the campaign in session 1, scene 1, then Vi (outside the scene) and Ada (in it)
     */
    private function campaignInAScene(): array
    {
        // Ada, created while the scene is under way, joins its cast and the party; Vi, created before the session, does not.
        $id = $this->createCampaign();
        $vi = $this->createCharacter($id, 'Vi', ['reflexes' => 7, 'luck' => -2]);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/sessions', $id));
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/scenes', $id), ['title' => 'At the gate']);
        self::assertResponseStatusCodeSame(201);
        $ada = $this->createCharacter($id, 'Ada', []);

        return [$id, $vi, $ada];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function createCharacter(string $campaignId, string $name, array $values): string
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $campaignId), ['name' => $name, 'values' => $values]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function createCampaign(string $gameSystemKey = 'rolling'): string
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => $gameSystemKey]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<mixed>
     */
    private function campaign(string $id): array
    {
        $this->client->request('GET', '/api/campaigns/'.$id);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    private function assertJournalIsEmpty(string $campaignId): void
    {
        $this->client->request('GET', \sprintf('/api/campaigns/%s/journal', $campaignId));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json());
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
