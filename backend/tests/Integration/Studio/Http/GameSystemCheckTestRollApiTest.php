<?php

declare(strict_types=1);

namespace App\Tests\Integration\Studio\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Studio\Infrastructure\Http\CheckTestRollBandResponse;
use App\Studio\Infrastructure\Http\CheckTestRollPoolDieResponse;
use App\Studio\Infrastructure\Http\CheckTestRollPoolResponse;
use App\Studio\Infrastructure\Http\CheckTestRollPushResponse;
use App\Studio\Infrastructure\Http\CheckTestRollResponse;
use App\Studio\Infrastructure\Http\GameSystemCheckController;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Test rolls of a check over the Studio API. Owner only: another user's draft is not found. Runs
 * against valid/v2-checks: the check "skill" rolls 1d10 and adds {hands} + {edge} (hands is a number
 * of 0 to 10, default 4; edge is derived, {hands}/2) with a difficulty of 9 to 29 (default 13) and
 * bands bounded by it; "luck" rolls d% against 20 and the alarm clock (6 segments), with no difficulty.
 * The pool cases save "grit" in the draft: {hands} dice of 6 sides that succeed on 5+ (hands 4 by default),
 * bands miss up to 0, weak up to 1 and strong above, and a push "Take a Condition".
 */
#[CoversClass(GameSystemCheckController::class)]
#[CoversClass(CheckTestRollResponse::class)]
#[CoversClass(CheckTestRollBandResponse::class)]
#[CoversClass(CheckTestRollPoolResponse::class)]
#[CoversClass(CheckTestRollPoolDieResponse::class)]
#[CoversClass(CheckTestRollPushResponse::class)]
final class GameSystemCheckTestRollApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string BASE = '/api/studio/game-systems';
    private const string PATH = self::BASE.'/mine/checks/skill/test-rolls';
    private const string GRIT = self::BASE.'/mine/checks/grit/test-rolls';
    private const string GRIT_PUSH = self::GRIT.'/push';

    private KernelBrowser $client;
    private int $releases = 0;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    #[Test]
    public function noSessionIsUnauthorized(): void
    {
        $this->testRoll(self::PATH, []);

        self::assertResponseStatusCodeSame(401);
    }

    #[Test]
    public function onlyAGameManagerEntersStudio(): void
    {
        $this->signIn('pat@example.com', ['SOLO_PLAYER']);

        $this->testRoll(self::PATH, []);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function aRollWithoutSamplesUsesTheDefaultsOfTheSavedDraft(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->scriptRolls(7);

        $this->testRoll(self::PATH, []);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'dice' => ['expression' => '1d10', 'total' => 7, 'groups' => [['notation' => '1d10', 'sides' => 10, 'dice' => [['value' => 7, 'kept' => true]], 'subtotal' => 7]]],
            'pool' => null,
            'push' => null,
            'rolledSuccesses' => null,
            'modifier' => 6,
            'situational' => 0,
            'difficulty' => 13,
            'total' => 13,
            'bands' => [
                ['key' => 'fail', 'label' => 'Fail', 'bound' => 12],
                ['key' => 'success', 'label' => 'Success', 'bound' => 17],
                ['key' => 'crit', 'label' => 'Critical success', 'bound' => null],
            ],
            'band' => 'success',
        ], $this->json());
    }

    #[Test]
    public function theSamplesTheDifficultyAndTheSituationalModifierShapeTheRoll(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->scriptRolls(10);

        $this->testRoll(self::PATH, ['sheet' => ['hands' => 10], 'trackers' => [], 'difficulty' => 29, 'situational' => -4]);

        self::assertResponseStatusCodeSame(200);
        $roll = $this->json();
        // The dice 10, the modifier 10 + 5, the situational -4: 21 against the bounds 28 and 33.
        self::assertSame([15, -4, 29, 21, 'fail'], [$roll['modifier'], $roll['situational'], $roll['difficulty'], $roll['total'], $roll['band']]);
        self::assertSame([28, 33, null], $this->bounds());
    }

    #[Test]
    public function nullOptionsAreTheDefaultsAndACheckWithoutADifficultyReportsNone(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->scriptRolls(50);

        $this->testRoll(self::BASE.'/mine/checks/luck/test-rolls', ['sheet' => null, 'difficulty' => null, 'situational' => null, 'trackers' => ['alarm' => 4]]);

        self::assertResponseStatusCodeSame(200);
        $roll = $this->json();
        // The tracker bound reads the sampled clock.
        self::assertSame([null, 'good', [20, 4, null]], [$roll['difficulty'], $roll['band'], $this->bounds()]);
    }

    #[Test]
    public function nullSheetAndTrackersAreAbsentAndEmptyListsAreEmptyObjects(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->scriptRolls(50, 50, 50);
        foreach ([['sheet' => null, 'trackers' => null], ['sheet' => []], ['trackers' => []]] as $body) {
            $this->testRoll(self::BASE.'/mine/checks/luck/test-rolls', $body);

            self::assertResponseStatusCodeSame(200, json_encode($body, \JSON_THROW_ON_ERROR));
            // The defaults: the clock starts at 0, so the tracker bound is 0.
            self::assertSame([null, 'good', [20, 0, null]], [$this->json()['difficulty'], $this->json()['band'], $this->bounds()]);
        }
    }

    #[Test]
    public function aTestRollNeverChangesTheDraft(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $before = $this->checks();
        $this->scriptRolls(3, 3);

        $this->testRoll(self::PATH, ['sheet' => ['hands' => 1]]);
        self::assertResponseStatusCodeSame(200);
        $this->testRoll(self::PATH, ['difficulty' => 99]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame($before, $this->checks());
        self::assertSame(1, $before['version']);
    }

    #[Test]
    public function aTestRollUsesTheSavedDraftNotAnEarlierOne(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $luck = ['key' => 'luck', 'label' => 'Luck', 'dice' => '1d4', 'modifier' => '{hands}', 'bands' => [['key' => 'only', 'label' => 'Only']]];
        $this->client->jsonRequest('PUT', self::BASE.'/mine/checks/luck', ['expectedVersion' => 1, 'check' => $luck]);
        self::assertResponseStatusCodeSame(200);
        $this->scriptRolls(2);

        $this->testRoll(self::BASE.'/mine/checks/luck/test-rolls', ['sheet' => ['hands' => 3]]);

        self::assertResponseStatusCodeSame(200);
        $roll = $this->json();
        self::assertIsArray($roll['dice']);
        self::assertSame(['1d4', 3, 5, 'only'], [$roll['dice']['expression'], $roll['modifier'], $roll['total'], $roll['band']]);
    }

    #[Test]
    public function aVersion1DraftIsRefusedWithTheSchemaVersionPath(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('journal', 'valid/contract-doc-example');

        $this->testRoll(self::BASE.'/journal/checks/skill/test-rolls', []);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('schemaVersion: ', $this->error());
    }

    #[Test]
    public function anotherUsersDraftAnUnknownDraftAndAnUnknownCheckAreNotFound(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->client->request('POST', '/api/auth/logout');
        $this->signIn('bob@example.com', ['GAME_MANAGER']);

        foreach (['/mine/checks/skill', '/nothing/checks/skill'] as $path) {
            $this->testRoll(self::BASE.$path.'/test-rolls', []);
            self::assertResponseStatusCodeSame(404, $path);
            self::assertNotSame('', $this->error());
        }

        $this->client->request('POST', '/api/auth/logout');
        $this->signIn('ann@example.com', ['GAME_MANAGER'], false);
        $this->testRoll(self::BASE.'/mine/checks/ghost/test-rolls', []);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('The draft has no check "ghost".', $this->error());
    }

    #[Test]
    public function aBodyThatIsNotJsonIsUnsupported(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->request('POST', self::PATH, server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: 'sheet=1');

        self::assertResponseStatusCodeSame(415);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'broken JSON' => ['{"sheet": '];
        yield 'an empty body' => [''];
        yield 'a list' => ['[1, 2]'];
        yield 'an empty list' => ['[]'];
        yield 'a string' => ['"hands"'];
        yield 'null' => ['null'];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function aBodyThatIsNotAJsonObjectIsABadRequest(string $body): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->request('POST', self::PATH, server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Send a JSON object', $this->error());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}> the check, the body, the message
     */
    public static function badInput(): iterable
    {
        yield 'a key the sheet lacks' => ['skill', ['sheet' => ['ghost' => 1]], 'sheet.ghost: is not a field of the sheet'];
        yield 'a derived field' => ['skill', ['sheet' => ['edge' => 1]], 'sheet.edge: is derived, so it takes no sample'];
        yield 'a string sample' => ['skill', ['sheet' => ['hands' => '5']], 'sheet.hands: must be an integer'];
        yield 'a float sample' => ['skill', ['sheet' => ['hands' => 5.5]], 'sheet.hands: must be an integer'];
        yield 'a null sample' => ['skill', ['sheet' => ['hands' => null]], 'sheet.hands: must be an integer'];
        yield 'a sample below the min' => ['skill', ['sheet' => ['hands' => -1]], 'sheet.hands: must be 0 to 10, got -1'];
        yield 'a sample above the max' => ['skill', ['sheet' => ['hands' => 11]], 'sheet.hands: must be 0 to 10, got 11'];
        // Request::toArray() cannot tell `{}` from `[]`, so a list is read as an object keyed by its indexes.
        yield 'a sheet that is a list' => ['skill', ['sheet' => [5]], 'sheet.0: is not a field of the sheet'];
        yield 'trackers that are a list' => ['luck', ['trackers' => [5]], 'trackers.0: is not a tracker of the draft'];
        yield 'a sheet that is a string' => ['skill', ['sheet' => 'hands'], 'sheet: must be an object'];
        yield 'an unknown tracker' => ['luck', ['trackers' => ['ghost' => 1]], 'trackers.ghost: is not a tracker of the draft'];
        yield 'a tracker that is not an integer' => ['luck', ['trackers' => ['alarm' => 'high']], 'trackers.alarm: must be an integer'];
        yield 'a clock above its segments' => ['luck', ['trackers' => ['alarm' => 7]], 'trackers.alarm: must be 0 to 6, got 7'];
        yield 'trackers that are a number' => ['luck', ['trackers' => 3], 'trackers: must be an object'];
        yield 'a difficulty below the range' => ['skill', ['difficulty' => 8], 'difficulty: must be 9 to 29, got 8'];
        yield 'a difficulty above the range' => ['skill', ['difficulty' => 30], 'difficulty: must be 9 to 29, got 30'];
        yield 'a difficulty that is a string' => ['skill', ['difficulty' => '13'], 'difficulty: must be an integer'];
        yield 'a difficulty on a check without one' => ['luck', ['difficulty' => 5], 'difficulty: check "luck" declares no difficulty'];
        yield 'a situational modifier below -100' => ['skill', ['situational' => -101], 'situational: must be -100 to 100, got -101'];
        yield 'a situational modifier above 100' => ['skill', ['situational' => 101], 'situational: must be -100 to 100, got 101'];
        yield 'a situational modifier that is a float' => ['skill', ['situational' => 1.5], 'situational: must be an integer'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('badInput')]
    public function aValueTheDraftDoesNotAcceptIsRefusedOnItsPathAndNothingIsRolled(string $check, array $body, string $message): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        // No number is scripted: a roll would fail with a LogicException, not a 422.
        $this->scriptRolls();

        $this->testRoll(self::BASE.'/mine/checks/'.$check.'/test-rolls', $body);

        self::assertResponseStatusCodeSame(422);
        self::assertTrue(str_starts_with($this->error(), $message), $this->error());
    }

    /**
     * @return iterable<string, array{int, int, ?string}> the hands sample, the status, the start of the error
     */
    public static function handsSamples(): iterable
    {
        yield 'a sample that makes a formula divide by zero' => [1, 422, 'Check "luck" cannot be test-rolled: '];
        yield 'a sample the same check rolls with' => [4, 200, null];
    }

    #[Test]
    #[DataProvider('handsSamples')]
    public function aFormulaThatFailsForTheSamplesIsRefusedAndNothingIsRolled(int $hands, int $status, ?string $error): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        // edge is {hands}/2, so hands 1 makes the modifier divide by zero.
        $luck = ['key' => 'luck', 'label' => 'Luck', 'dice' => 'd%', 'modifier' => '10 / {edge}', 'bands' => [['key' => 'only', 'label' => 'Only']]];
        $this->client->jsonRequest('PUT', self::BASE.'/mine/checks/luck', ['expectedVersion' => 1, 'check' => $luck]);
        self::assertResponseStatusCodeSame(200);
        // Without a scripted number a roll fails with a LogicException, so a 422 means nothing was rolled.
        200 === $status ? $this->scriptRolls(40) : $this->scriptRolls();

        $this->testRoll(self::BASE.'/mine/checks/luck/test-rolls', ['sheet' => ['hands' => $hands]]);

        self::assertResponseStatusCodeSame($status);
        null === $error ? self::assertSame(45, $this->json()['total']) : self::assertTrue(str_starts_with($this->error(), $error), $this->error());
    }

    #[Test]
    public function aPoolCheckRollsAPoolSizedFromTheSamplesAndTheSuccessesAreTheTotal(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->saveGrit();
        $this->scriptRolls(1, 5, 6);

        $this->testRoll(self::GRIT, ['sheet' => ['hands' => 3]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'dice' => null,
            'pool' => [
                'size' => 3,
                'sides' => 6,
                'successAt' => 5,
                'count' => 3,
                'countFormula' => '{hands}',
                'dice' => [['face' => 1, 'success' => false, 'rerolled' => false], ['face' => 5, 'success' => true, 'rerolled' => false], ['face' => 6, 'success' => true, 'rerolled' => false]],
                'successes' => 2,
            ],
            'push' => ['label' => 'Take a Condition'],
            'rolledSuccesses' => null,
            'modifier' => 0,
            'situational' => 0,
            'difficulty' => null,
            'total' => 2,
            'bands' => [['key' => 'miss', 'label' => 'Miss', 'bound' => 0], ['key' => 'weak', 'label' => 'Weak', 'bound' => 1], ['key' => 'strong', 'label' => 'Strong', 'bound' => null]],
            'band' => 'strong',
        ], $this->json());
    }

    #[Test]
    public function aNumberCountHasNoFormulaAndACheckWithoutAPushTellsNone(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->saveGrit(['count' => 2, 'sides' => 10, 'successAt' => 8], null);
        $this->scriptRolls(9, 3);

        $this->testRoll(self::GRIT, []);

        self::assertResponseStatusCodeSame(200);
        $roll = $this->json();
        self::assertIsArray($roll['pool']);
        self::assertSame([2, null, 1, null, 'weak'], [$roll['pool']['count'], $roll['pool']['countFormula'], $roll['total'], $roll['push'], $roll['band']]);
    }

    /**
     * @return iterable<string, array{int, int, int}> the hands sample, the situational modifier, the size rolled
     */
    public static function sizes(): iterable
    {
        yield 'the situational modifier adds dice' => [3, 4, 7];
        yield 'or takes them' => [3, -2, 1];
        yield 'never below one die' => [3, -100, 1];
        yield 'never above a hundred dice' => [10, 100, 100];
    }

    #[Test]
    #[DataProvider('sizes')]
    public function theSizeIsTheCountPlusTheSituationalModifierKeptInOneToAHundred(int $hands, int $situational, int $size): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->saveGrit();
        $this->scriptRolls(...array_fill(0, $size, 1));

        $this->testRoll(self::GRIT, ['sheet' => ['hands' => $hands], 'situational' => $situational]);

        self::assertResponseStatusCodeSame(200);
        $pool = $this->json()['pool'];
        self::assertIsArray($pool);
        self::assertSame([$size, $hands], [$pool['size'], $pool['count']]);
    }

    #[Test]
    public function aCountThatFailsForTheSamplesIsRefusedAndNothingIsRolled(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        // hands 4 by default: the count divides by zero.
        $this->saveGrit(['count' => ['formula' => '10 / ({hands} - 4)'], 'sides' => 6, 'successAt' => 5]);
        // No number is scripted: a roll would fail with a LogicException, not a 422.
        $this->scriptRolls();

        $this->testRoll(self::GRIT, []);

        self::assertResponseStatusCodeSame(422);
        self::assertTrue(str_starts_with($this->error(), 'Check "grit" cannot be test-rolled: '), $this->error());
    }

    #[Test]
    public function aPoolCheckWhoseSituationalModifierIsOutOfRangeIsRefused(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->saveGrit();

        $this->testRoll(self::GRIT, ['situational' => 101]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('situational: must be -100 to 100, got 101', $this->error());
    }

    #[Test]
    public function aPushReRollsTheFailedDiceOfTheRollAndDoesNotApplyTheCost(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->saveGrit();
        $before = $this->checks();
        $this->scriptRolls(6, 2);

        $this->testRoll(self::GRIT_PUSH, ['faces' => [1, 5, 2], 'sheet' => ['hands' => 3]]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'dice' => null,
            'pool' => [
                'size' => 3,
                'sides' => 6,
                'successAt' => 5,
                'count' => 3,
                'countFormula' => '{hands}',
                'dice' => [['face' => 6, 'success' => true, 'rerolled' => true], ['face' => 5, 'success' => true, 'rerolled' => false], ['face' => 2, 'success' => false, 'rerolled' => true]],
                'successes' => 2,
            ],
            'push' => ['label' => 'Take a Condition'],
            'rolledSuccesses' => 1,
            'modifier' => 0,
            'situational' => 0,
            'difficulty' => null,
            'total' => 2,
            'bands' => [['key' => 'miss', 'label' => 'Miss', 'bound' => 0], ['key' => 'weak', 'label' => 'Weak', 'bound' => 1], ['key' => 'strong', 'label' => 'Strong', 'bound' => null]],
            'band' => 'strong',
        ], $this->json());
        self::assertSame($before, $this->checks());
    }

    #[Test]
    public function aPushSizesThePoolForTheSituationalModifierOfTheRoll(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->saveGrit();
        $this->scriptRolls(5);

        // hands 4 and a situational +1: five dice, one of which failed.
        $this->testRoll(self::GRIT_PUSH, ['faces' => [5, 6, 5, 6, 1], 'situational' => 1]);

        self::assertResponseStatusCodeSame(200);
        $roll = $this->json();
        self::assertIsArray($roll['pool']);
        self::assertSame([5, 4, 5], [$roll['total'], $roll['rolledSuccesses'], $roll['pool']['size']]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}> the body
     */
    public static function wrongFaces(): iterable
    {
        yield 'fewer faces than the size' => [['faces' => [1, 2], 'sheet' => ['hands' => 3]]];
        yield 'more faces than the size' => [['faces' => [1, 2, 3, 4], 'sheet' => ['hands' => 3]]];
        yield 'no faces' => [['faces' => []]];
        yield 'a face of zero' => [['faces' => [0, 2, 3, 4]]];
        yield 'a face above the sides' => [['faces' => [1, 2, 3, 7]]];
        yield 'a face that is a string' => [['faces' => [1, '2', 3, 4]]];
        yield 'a face that is a float' => [['faces' => [1, 2.5, 3, 4]]];
        yield 'a face that is null' => [['faces' => [1, null, 3, 4]]];
        yield 'faces that are a number' => [['faces' => 4]];
        yield 'faces that are an object' => [['faces' => ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]]];
        yield 'faces that are null' => [['faces' => null]];
        yield 'a sample the sheet lacks' => [['faces' => [1, 2, 3, 4], 'sheet' => ['ghost' => 1]]];
        yield 'a situational modifier that is no integer' => [['faces' => [1, 2, 3, 4], 'situational' => 'one']];
        yield 'a difficulty on a check without one' => [['faces' => [1, 2, 3, 4], 'difficulty' => 5]];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('wrongFaces')]
    public function facesThatAreNotTheRollOfThePoolAreRefusedAndNothingIsRolled(array $body): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->saveGrit();
        $this->scriptRolls();

        $this->testRoll(self::GRIT_PUSH, $body);

        self::assertResponseStatusCodeSame(422);
        self::assertNotSame('', $this->error());
    }

    #[Test]
    public function theMessageOfWrongFacesStartsWithTheirPath(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->saveGrit();

        $this->testRoll(self::GRIT_PUSH, ['faces' => [1, 2], 'sheet' => ['hands' => 3]]);
        self::assertTrue(str_starts_with($this->error(), 'faces: A pool of this size holds 3 dice, 2 faces given'), $this->error());
        $this->testRoll(self::GRIT_PUSH, ['faces' => [1, 2, 7]]);
        self::assertSame('faces: ', substr($this->error(), 0, 7));
    }

    #[Test]
    public function aCheckWithoutAPushCannotBePushed(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->saveGrit(push: null);
        $this->scriptRolls();

        $this->testRoll(self::GRIT_PUSH, ['faces' => [1, 2, 3, 4]]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Check "grit" cannot be pushed: it declares no push.', $this->error());
    }

    #[Test]
    public function aDiceCheckCannotBePushed(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->scriptRolls();

        $this->testRoll(self::PATH.'/push', ['faces' => [7]]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Check "skill" cannot be pushed: it declares no push.', $this->error());
    }

    #[Test]
    public function aRollWhoseDiceAllSucceededCannotBePushed(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->saveGrit();
        $this->scriptRolls();

        $this->testRoll(self::GRIT_PUSH, ['faces' => [5, 6, 5, 6]]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('Check "grit" cannot be pushed: no die failed.', $this->error());
    }

    #[Test]
    public function aPushWhoseCountFailsForTheSamplesIsRefusedAndNothingIsReRolled(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->saveGrit(['count' => ['formula' => '10 / ({hands} - 4)'], 'sides' => 6, 'successAt' => 5]);
        $this->scriptRolls();

        $this->testRoll(self::GRIT_PUSH, ['faces' => [1, 2, 3, 4]]);

        self::assertResponseStatusCodeSame(422);
        self::assertTrue(str_starts_with($this->error(), 'Check "grit" cannot be test-rolled: '), $this->error());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedPushBodies(): iterable
    {
        yield 'broken JSON' => ['{"faces": '];
        yield 'an empty body' => [''];
        yield 'a list' => ['[1, 2]'];
        yield 'an empty object' => ['{}'];
        yield 'an object without faces' => ['{"sheet": {"hands": 3}}'];
        yield 'null' => ['null'];
    }

    #[Test]
    #[DataProvider('malformedPushBodies')]
    public function aPushBodyWithoutAJsonObjectOfFacesIsABadRequest(string $body): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->saveGrit();

        $this->client->request('POST', self::GRIT_PUSH, server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('Send a JSON object with a list "faces"', $this->error());
    }

    #[Test]
    public function aPushBodyThatIsNotJsonIsUnsupported(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');

        $this->client->request('POST', self::GRIT_PUSH, server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: 'faces=1');

        self::assertResponseStatusCodeSame(415);
    }

    #[Test]
    public function noSessionOrNoGameManagerCannotPush(): void
    {
        $this->testRoll(self::GRIT_PUSH, ['faces' => [1]]);
        self::assertResponseStatusCodeSame(401);

        $this->signIn('pat@example.com', ['SOLO_PLAYER']);
        $this->testRoll(self::GRIT_PUSH, ['faces' => [1]]);
        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function aPushOfAnotherUsersDraftAnUnknownDraftOrAnUnknownCheckIsNotFound(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('mine');
        $this->saveGrit();
        $this->client->request('POST', '/api/auth/logout');
        $this->signIn('bob@example.com', ['GAME_MANAGER']);

        foreach (['/mine/checks/grit', '/nothing/checks/grit'] as $path) {
            $this->testRoll(self::BASE.$path.'/test-rolls/push', ['faces' => [1]]);
            self::assertResponseStatusCodeSame(404, $path);
        }

        $this->client->request('POST', '/api/auth/logout');
        $this->signIn('ann@example.com', ['GAME_MANAGER'], false);
        $this->testRoll(self::BASE.'/mine/checks/ghost/test-rolls/push', ['faces' => [1]]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('The draft has no check "ghost".', $this->error());
    }

    #[Test]
    public function aPushOfAVersion1DraftIsRefusedWithTheSchemaVersionPath(): void
    {
        $this->signIn('ann@example.com', ['GAME_MANAGER']);
        $this->draft('journal', 'valid/contract-doc-example');

        $this->testRoll(self::BASE.'/journal/checks/skill/test-rolls/push', ['faces' => [1]]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringStartsWith('schemaVersion: ', $this->error());
    }

    /**
     * Saves the pool check "grit" in the draft "mine".
     *
     * @param array<string, mixed>  $pool
     * @param ?array<string, mixed> $push
     */
    private function saveGrit(array $pool = ['count' => ['formula' => '{hands}'], 'sides' => 6, 'successAt' => 5], ?array $push = ['label' => 'Take a Condition']): void
    {
        $check = ['key' => 'grit', 'label' => 'Grit', 'pool' => $pool, 'bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => 0], ['key' => 'weak', 'label' => 'Weak', 'upTo' => 1], ['key' => 'strong', 'label' => 'Strong']]];
        if (null !== $push) {
            $check['push'] = $push;
        }

        $this->client->jsonRequest('POST', self::BASE.'/mine/checks', ['expectedVersion' => $this->checks()['version'], 'check' => $check]);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * Sends the body as a JSON object, `{}` when it is empty.
     *
     * @param array<string, mixed> $body
     */
    private function testRoll(string $path, array $body): void
    {
        $this->client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode((object) $body, \JSON_THROW_ON_ERROR));
    }

    /**
     * Makes Randomness roll these numbers, in order, for the next request.
     */
    private function scriptRolls(int ...$numbers): void
    {
        self::getContainer()->set(RandomNumberGenerator::class, new ScriptedRandomNumberGenerator(...$numbers));
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(string $email, array $roles, bool $create = true): void
    {
        if ($create) {
            $container = self::getContainer();
            $id = $container->get(UserIdGenerator::class)->generate()->toString();
            $container->get(CommandBus::class)->dispatch(new CreateUser($id, $email, self::PASSWORD, $roles));
        }

        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    /**
     * Publishes the fixture and copies it as the signed-in user's draft $key.
     */
    private function draft(string $key, string $fixture = 'valid/v2-checks'): void
    {
        $release = ReleaseArrays::fixture($fixture);
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease(\sprintf('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3fb%03d', ++$this->releases), $release, false));
        $gameSystem = $release['gameSystem'] ?? null;
        self::assertIsArray($gameSystem);
        $this->client->jsonRequest('POST', self::BASE, ['key' => $key, 'name' => $key, 'source' => ['key' => $gameSystem['key']]]);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @return list<mixed> the bound of each band of the last response
     */
    private function bounds(): array
    {
        $bands = $this->json()['bands'] ?? null;
        self::assertIsArray($bands);

        return array_column($bands, 'bound');
    }

    /**
     * @return array<mixed>
     */
    private function checks(): array
    {
        $this->client->request('GET', self::BASE.'/mine/checks');
        self::assertResponseIsSuccessful();

        return $this->json();
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
}
