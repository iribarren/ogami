<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Application\CharacterIdGenerator;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Infrastructure\Http\CampaignCharactersController;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\CampaignNpcsController;
use App\Play\Infrastructure\Http\CharacterResponse;
use App\Play\Infrastructure\Http\PinnedReleaseResponse;
use App\Play\Infrastructure\Http\SheetFieldResponse;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\CreationRelease;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Play\RescriptableRandomNumberGenerator;
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
 * Campaign Characters over the Play API, on the release "valid/v2-sheet" without its Scene Types
 * (Play does not play the character step yet): Tags "crew" and "leader", and a sheet of a role
 * (text), a look (multiline text), reflexes 1 to 10 defaulting to 4 and luck (numbers), hp (track,
 * fixed max 40), humanity (track), gear (list, 20 items) and cyberware (list).
 */
#[CoversClass(CampaignCharactersController::class)]
#[CoversClass(CampaignController::class)]
#[CoversClass(CampaignNpcsController::class)]
#[CoversClass(CharacterResponse::class)]
#[CoversClass(PinnedReleaseResponse::class)]
#[CoversClass(SheetFieldResponse::class)]
final class CampaignCharactersApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    private const string UNKNOWN_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f6999';
    private const string MALFORMED = 'Send a JSON object with a string "name" and, optionally, lists of strings or null "tags" and "freeTags" and an object or null "values", such as {"name": "Vi", "tags": ["ally"], "values": {"reflexes": 7}}.';
    private const array DEFAULT_VALUES = [
        'role' => '',
        'look' => '',
        'reflexes' => 4,
        'luck' => 0,
        'debt' => 0,
        'hp' => ['current' => 40, 'max' => 40],
        'humanity' => ['current' => 0, 'max' => 0],
        'gear' => [],
        'cyberware' => [],
    ];

    private KernelBrowser $client;
    private RescriptableRandomNumberGenerator $random;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->random = new RescriptableRandomNumberGenerator();
        self::getContainer()->set(RandomNumberGenerator::class, $this->random);
        $content = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-sheet'), 'sceneTypes', []);
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9101', $content, false));
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9102', CreationRelease::content(), false));
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9103', CreationRelease::mixedContent(), false));
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9104', CreationRelease::budgetsContent(), false));
    }

    #[Test]
    public function aNewCampaignShowsTheSheetOfItsReleaseAndNoCharacters(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->request('GET', '/api/campaigns/'.$id);

        self::assertResponseIsSuccessful();
        $campaign = $this->json();
        self::assertSame([], $campaign['characters'] ?? null);
        $pinned = $campaign['pinnedRelease'] ?? null;
        self::assertIsArray($pinned);
        $sheet = $pinned['sheet'] ?? null;
        self::assertIsArray($sheet);
        $sheet = array_map(static fn (mixed $field): array => (array) $field, array_values($sheet));
        self::assertSame(['role', 'look', 'reflexes', 'luck', 'debt', 'hp', 'humanity', 'gear', 'cyberware'], array_column($sheet, 'key'));
        self::assertSame(
            ['key' => 'role', 'label' => 'Role', 'hint' => 'What you do for the crew.', 'group' => 'Identity', 'type' => 'text', 'min' => null, 'max' => null, 'default' => null, 'multiline' => false, 'maxLength' => 200, 'itemLabel' => null, 'maxItems' => null, 'formula' => null, 'roll' => null, 'startMax' => null, 'span' => 1, 'options' => null],
            $sheet[0],
        );
        self::assertSame(['number', 1, 10, 4], [$sheet[2]['type'] ?? null, $sheet[2]['min'] ?? null, $sheet[2]['max'] ?? null, $sheet[2]['default'] ?? null]);
        self::assertSame(['number', -1_000_000, 1_000_000, 0], [$sheet[3]['type'] ?? null, $sheet[3]['min'] ?? null, $sheet[3]['max'] ?? null, $sheet[3]['default'] ?? null]);
        self::assertSame([['track', 40], ['track', null]], [[$sheet[5]['type'] ?? null, $sheet[5]['max'] ?? null], [$sheet[6]['type'] ?? null, $sheet[6]['max'] ?? null]]);
        self::assertSame([['list', 'Item', 20], ['list', null, 50]], [[$sheet[7]['type'] ?? null, $sheet[7]['itemLabel'] ?? null, $sheet[7]['maxItems'] ?? null], [$sheet[8]['type'] ?? null, $sheet[8]['itemLabel'] ?? null, $sheet[8]['maxItems'] ?? null]]);
    }

    #[Test]
    public function thePinnedReleaseExposesTheCreationRulesOfItsSheet(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        $this->client->request('GET', '/api/campaigns/'.$this->createCampaign(CreationRelease::KEY));

        $pinned = $this->json()['pinnedRelease'] ?? null;
        self::assertIsArray($pinned);
        /** @var array<string, array<string, mixed>> $sheet */
        $sheet = array_column((array) $pinned['sheet'], null, 'key');
        self::assertSame(['3d6*5', '(2d6+6)*5', null, null], [$sheet['str']['roll'], $sheet['con']['roll'], $sheet['role']['roll'], $sheet['hp']['roll']]);
        self::assertSame(['{half}', null, null], [$sheet['hp']['startMax'], $sheet['str']['startMax'], $sheet['half']['startMax']]);
        self::assertArrayNotHasKey('pointBudget', $pinned);
        self::assertSame([['key' => 'points', 'label' => 'Points', 'total' => 100, 'fields' => ['str', 'con'], 'min' => 15, 'max' => 90]], $pinned['pointBudgets'] ?? null, 'A legacy budget is the key "points".');
    }

    #[Test]
    public function thePinnedReleaseExposesSeveralBudgets(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        $this->client->request('GET', '/api/campaigns/'.$this->createCampaign(CreationRelease::BUDGETS_KEY));

        $pinned = $this->json()['pinnedRelease'] ?? null;
        self::assertIsArray($pinned);
        self::assertArrayNotHasKey('pointBudget', $pinned);
        self::assertSame([
            ['key' => 'attributes', 'label' => 'Attribute points', 'total' => 100, 'fields' => ['str', 'con'], 'min' => 15, 'max' => 90],
            ['key' => 'skills', 'label' => 'Skill points', 'total' => 5, 'fields' => ['lore', 'sneak'], 'min' => null, 'max' => 3],
        ], $pinned['pointBudgets'] ?? null);
    }

    #[Test]
    public function aCharacterByRollRollsTheAttributesAndSpendsTheSkillPoints(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign(CreationRelease::BUDGETS_KEY);
        // Strength 4+5+6 -> 75, Constitution 3+4 -> 65, Luck 1+1+2 -> 20.
        $this->random->script(4, 5, 6, 3, 4, 1, 1, 2);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi', 'method' => 'roll', 'values' => ['lore' => 3, 'sneak' => 2]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['str' => 75, 'con' => 65, 'lore' => 3, 'sneak' => 2, 'luck' => 20], $this->json()['values'] ?? null);
    }

    #[Test]
    public function aCharacterByPointsSpendsBothBudgets(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign(CreationRelease::BUDGETS_KEY);
        $this->random->script(3, 3, 3);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi', 'method' => 'points', 'values' => ['str' => 50, 'con' => 50, 'lore' => 3, 'sneak' => 2]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['str' => 50, 'con' => 50, 'lore' => 3, 'sneak' => 2, 'luck' => 45], $this->json()['values'] ?? null);
    }

    #[Test]
    public function overOneOfTwoBudgetsIsRefusedByItsLabelAndChangesNothing(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign(CreationRelease::BUDGETS_KEY);
        $this->random->script(1, 1, 1, 1, 1, 1, 1, 1);
        $url = \sprintf('/api/campaigns/%s/characters', $id);

        $this->client->jsonRequest('POST', $url, ['name' => 'Vi', 'method' => 'roll', 'values' => ['lore' => 3, 'sneak' => 3]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => '"Skill points" allows 5 points, 6 spent.', 'code' => 'point_budget_exceeded'], $this->json());

        $this->client->jsonRequest('POST', $url, ['name' => 'Vi', 'method' => 'points', 'values' => ['str' => 50, 'con' => 50, 'lore' => 4, 'sneak' => 1]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Field "lore" of "Skill points" must be 0 to 3 when spending points, got 4.', 'code' => 'outside_creation_bounds'], $this->json());

        $this->client->jsonRequest('POST', $url, ['name' => 'Vi', 'values' => ['lore' => 1, 'sneak' => 1]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('creation_method_required', $this->json()['code'] ?? null);
        self::assertSame([], $this->campaignCharacters($id));
    }

    #[Test]
    public function aReleaseWithoutCreationRulesExposesNoneOfThem(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        $this->client->request('GET', '/api/campaigns/'.$this->createCampaign());

        $pinned = $this->json()['pinnedRelease'] ?? null;
        self::assertIsArray($pinned);
        self::assertSame([], $pinned['pointBudgets']);
        foreach ((array) $pinned['sheet'] as $field) {
            $field = (array) $field;
            self::assertArrayHasKey('roll', $field);
            self::assertArrayHasKey('startMax', $field);
            self::assertSame([null, null], [$field['roll'], $field['startMax']]);
        }
    }

    #[Test]
    public function aCharacterIsAddedWithTheDefaultOfEveryField(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => '  Vi ']);

        self::assertResponseStatusCodeSame(201);
        $character = $this->json();
        self::assertIsString($character['id'] ?? null);
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $character['id']);
        self::assertSame(['name' => 'Vi', 'tags' => [], 'freeTags' => [], 'values' => self::DEFAULT_VALUES, 'derived' => [], 'promotedFrom' => null], array_diff_key($character, ['id' => 1]));
        self::assertSame([$character], $this->campaignCharacters($id));
    }

    #[Test]
    public function aCharacterIsAddedWithEveryField(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $values = [
            'role' => ' Solo ',
            'look' => "Tall\nScarred",
            'reflexes' => 9,
            'luck' => -2,
            'hp' => ['current' => 25],
            'humanity' => ['current' => 6, 'max' => 8],
            'gear' => ['Knife', ' Pistol '],
            'cyberware' => [],
        ];

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi', 'tags' => ['crew', 'leader'], 'freeTags' => ['Fixer'], 'values' => $values]);

        self::assertResponseStatusCodeSame(201);
        $character = $this->json();
        self::assertSame(['Vi', ['crew', 'leader'], ['Fixer']], [$character['name'] ?? null, $character['tags'] ?? null, $character['freeTags'] ?? null]);
        self::assertSame(
            ['role' => 'Solo', 'look' => "Tall\nScarred", 'reflexes' => 9, 'luck' => -2, 'debt' => 0, 'hp' => ['current' => 25, 'max' => 40], 'humanity' => ['current' => 6, 'max' => 8], 'gear' => ['Knife', 'Pistol'], 'cyberware' => []],
            $character['values'] ?? null,
        );
        self::assertSame([$character], $this->campaignCharacters($id));
    }

    #[Test]
    public function aSheetWithoutDerivedFieldsShowsDerivedAsAnEmptyObject(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi']);

        self::assertResponseStatusCodeSame(201);
        self::assertStringContainsString('"derived":{}', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/api/campaigns/'.$id);
        self::assertStringContainsString('"derived":{}', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function nullOptionalFieldsReadAsTheirDefaults(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi', 'tags' => null, 'freeTags' => null, 'values' => null]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['name' => 'Vi', 'tags' => [], 'freeTags' => [], 'values' => self::DEFAULT_VALUES, 'derived' => [], 'promotedFrom' => null], array_diff_key($this->json(), ['id' => 1]));
    }

    #[Test]
    public function anEditReplacesEverythingAndAValueNotGivenGoesBackToItsDefault(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id, ['name' => 'Vi', 'tags' => ['crew'], 'freeTags' => ['Fixer'], 'values' => ['role' => 'Solo', 'luck' => 5, 'gear' => ['Knife']]]);

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Violet', 'tags' => ['leader'], 'values' => ['luck' => 2]]);

        self::assertResponseStatusCodeSame(200);
        $character = $this->json();
        self::assertSame($characterId, $character['id'] ?? null);
        self::assertSame(['Violet', ['leader'], []], [$character['name'] ?? null, $character['tags'] ?? null, $character['freeTags'] ?? null]);
        self::assertSame(array_replace(self::DEFAULT_VALUES, ['luck' => 2]), $character['values'] ?? null);
        self::assertSame([$character], $this->campaignCharacters($id));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'not JSON' => ['{'];
        yield 'a list' => ['[]'];
        yield 'no name' => ['{"tags": []}'];
        yield 'a name that is a number' => ['{"name": 3}'];
        yield 'tags that are a string' => ['{"name": "Vi", "tags": "crew"}'];
        yield 'tags that are not strings' => ['{"name": "Vi", "tags": [1]}'];
        yield 'free tags that are an object' => ['{"name": "Vi", "freeTags": {"a": "b"}}'];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function aMalformedBodyIsABadRequestAndNothingIsSaved(string $body): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id, ['name' => 'Vi']);

        foreach ([['POST', \sprintf('/api/campaigns/%s/characters', $id)], ['PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId)]] as [$method, $uri]) {
            $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $body);

            self::assertResponseStatusCodeSame(400);
            self::assertSame(['error' => self::MALFORMED], $this->json());
        }

        self::assertSame(['Vi'], array_column($this->campaignCharacters($id), 'name'));
    }

    #[Test]
    public function aBodyThatIsNotJsonIsUnsupported(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->request('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi']);

        self::assertResponseStatusCodeSame(415);
        self::assertSame(['error' => 'Send the Character as JSON.'], $this->json());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidCharacters(): iterable
    {
        yield 'values that are a string' => ['{"name": "Vi", "values": "many"}', 'The "values" must be an object of sheet field keys to values.'];
        yield 'values that are a list' => ['{"name": "Vi", "values": [1, 2]}', 'The "values" must be an object of sheet field keys to values.'];
        yield 'a blank name' => ['{"name": "  "}', 'The name must not be blank.'];
        yield 'a name over 100 characters' => ['{"name": "'.str_repeat('n', 101).'"}', 'The name must be at most 100 characters, got 101.'];
        yield 'a repeated tag' => ['{"name": "Vi", "tags": ["crew", "crew"]}', 'Tag "crew" is listed twice.'];
        yield 'a tag the release lacks' => ['{"name": "Vi", "tags": ["ghost"]}', 'Tag "ghost" not found in the pinned release.'];
        yield 'an unknown field' => ['{"name": "Vi", "values": {"ghost": 1}}', 'The sheet has no field "ghost".'];
        yield 'a number as a string' => ['{"name": "Vi", "values": {"luck": "3"}}', 'Field "luck" must be an integer.'];
        yield 'a number as a float' => ['{"name": "Vi", "values": {"luck": 3.5}}', 'Field "luck" must be an integer.'];
        yield 'a number out of bounds' => ['{"name": "Vi", "values": {"reflexes": 11}}', 'Field "reflexes" must be 1 to 10, got 11.'];
        yield 'a text that is a number' => ['{"name": "Vi", "values": {"role": 5}}', 'Field "role" must be a string.'];
        yield 'a single-line text with a newline' => ['{"name": "Vi", "values": {"role": "a\nb"}}', 'Field "role" is a single line.'];
        yield 'a list that is a string' => ['{"name": "Vi", "values": {"gear": "Knife"}}', 'Field "gear" must be a list of strings.'];
        yield 'too many list items' => ['{"name": "Vi", "values": {"gear": ['.implode(',', array_fill(0, 21, '"a"')).']}}', 'Field "gear" holds at most 20 items, got 21.'];
        yield 'a track that is a number' => ['{"name": "Vi", "values": {"hp": 5}}', 'Field "hp" must be {current, max}.'];
        yield 'a track above its max' => ['{"name": "Vi", "values": {"hp": {"current": 41}}}', 'The current of field "hp" must be 0 to 40, got 41.'];
        yield 'a track against its fixed max' => ['{"name": "Vi", "values": {"hp": {"current": 5, "max": 30}}}', 'Field "hp" has a fixed max of 40, got 30.'];
        yield 'a track without a max to set' => ['{"name": "Vi", "values": {"humanity": {"current": 5}}}', 'Field "humanity" must be {current, max} with integers.'];
    }

    #[Test]
    #[DataProvider('invalidCharacters')]
    public function aValueThatBreaksARuleIsUnprocessableAndNothingIsSaved(string $body, string $error): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id, ['name' => 'Vi', 'values' => ['luck' => 1]]);
        $before = $this->campaignCharacters($id);

        foreach ([['POST', \sprintf('/api/campaigns/%s/characters', $id)], ['PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId)]] as [$method, $uri]) {
            $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: $body);

            self::assertResponseStatusCodeSame(422);
            self::assertSame(['error' => $error], $this->json());
        }

        self::assertSame($before, $this->campaignCharacters($id));
    }

    #[Test]
    public function anUnknownOrForeignCampaignIsNotFound(): void
    {
        $this->signIn('bob@example.com', ['SOLO_PLAYER']);
        $bobs = $this->createCampaign();
        $bobsCharacter = $this->addCharacter($bobs, ['name' => 'Bob']);
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);

        foreach ([$bobs, self::UNKNOWN_ID] as $campaignId) {
            foreach ([
                ['POST', \sprintf('/api/campaigns/%s/characters', $campaignId)],
                ['PUT', \sprintf('/api/campaigns/%s/characters/%s', $campaignId, $bobsCharacter)],
                ['PUT', \sprintf('/api/campaigns/%s/characters/%s', $campaignId, rawurlencode(' '))],
            ] as [$method, $uri]) {
                $this->client->jsonRequest($method, $uri, ['name' => 'Vi']);

                self::assertResponseStatusCodeSame(404);
                self::assertSame(['error' => \sprintf('Campaign "%s" not found.', $campaignId)], $this->json());
            }
        }
    }

    #[Test]
    public function aCharacterTheCampaignDoesNotHoldIsNotFound(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $existing = $this->addCharacter($id, ['name' => 'Vi']);

        // Ids are opaque strings compared as given: the same id in another letter case is another id.
        foreach ([self::UNKNOWN_ID, 'not-a-uuid', ' ', strtoupper($existing)] as $characterId) {
            $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, rawurlencode($characterId)), ['name' => 'Brann']);

            self::assertResponseStatusCodeSame(404);
            self::assertSame(['error' => \sprintf('Character "%s" not found.', $characterId)], $this->json());
        }

        self::assertSame(['Vi'], array_column($this->campaignCharacters($id), 'name'));
    }

    #[Test]
    public function aGeneratedIdThatIsAlreadyTakenIsAConflict(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        self::getContainer()->set(CharacterIdGenerator::class, new readonly class implements CharacterIdGenerator {
            public function generate(): CharacterId
            {
                return CharacterId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001');
            }
        });
        $this->addCharacter($id, ['name' => 'Vi']);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Brann']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Character "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001" already exists.'], $this->json());
        self::assertSame(['Vi'], array_column($this->campaignCharacters($id), 'name'));
    }

    #[Test]
    public function aCampaignAtTheLimitRefusesAnotherCharacter(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $characters = array_map(static fn (int $i): array => ['id' => \sprintf('c-%d', $i), 'name' => 'Character '.$i, 'tags' => [], 'freeTags' => [], 'values' => [], 'promotedFrom' => null], range(1, 100));
        $this->connection()->executeStatement('UPDATE play_campaign SET characters = ? WHERE id = ?', [json_encode($characters, \JSON_THROW_ON_ERROR), $id]);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'One too many']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'A campaign holds at most 100 Characters.'], $this->json());
        self::assertCount(100, $this->campaignCharacters($id));
    }

    #[Test]
    public function aChangeOnACampaignSavedByAnotherRequestMeanwhileIsAConflict(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id, ['name' => 'Vi']);

        foreach ([
            ['POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Brann']],
            ['PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Renamed']],
        ] as [$method, $uri, $body]) {
            $this->requestWhileAnotherRequestSaves($id, $method, $uri, $body);

            self::assertResponseStatusCodeSame(409);
            self::assertSame(['error' => \sprintf('Campaign "%s" was changed by another request. Reload it and try again.', $id)], $this->json());
        }

        self::assertSame([[$characterId, 'Vi']], array_map(static fn (array $character): array => [$character['id'], $character['name']], $this->campaignCharacters($id)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableReleases(): iterable
    {
        yield 'release gone' => ['DELETE FROM studio_gamesystem_release'];
        yield 'unsupported schema version' => ['UPDATE studio_gamesystem_release SET schema_version = 99'];
    }

    #[Test]
    #[DataProvider('unreadableReleases')]
    public function aPinnedReleaseThatCannotBeReadIsAServerErrorWithoutInternals(string $breakTheRelease): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $characterId = $this->addCharacter($id, ['name' => 'Vi']);
        $this->connection()->executeStatement($breakTheRelease);

        foreach ([['POST', \sprintf('/api/campaigns/%s/characters', $id)], ['PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId)]] as [$method, $uri]) {
            $this->client->jsonRequest($method, $uri, ['name' => 'Brann']);

            self::assertResponseStatusCodeSame(500);
            self::assertSame(['error' => 'The GameSystem release this campaign plays can no longer be read.'], $this->json());
        }

        self::assertSame(1, $this->connection()->fetchOne('SELECT jsonb_array_length(characters) FROM play_campaign WHERE id = ?', [$id]));
    }

    #[Test]
    public function characterEndpointsAreForSoloPlayersOnly(): void
    {
        $requests = [
            ['POST', '/api/campaigns/'.self::UNKNOWN_ID.'/characters', ['name' => 'Vi']],
            ['PUT', \sprintf('/api/campaigns/%s/characters/%s', self::UNKNOWN_ID, self::UNKNOWN_ID), ['name' => 'Vi']],
        ];

        foreach ($requests as [$method, $uri, $body]) {
            $this->client->jsonRequest($method, $uri, $body);
            self::assertResponseStatusCodeSame(401);
        }

        foreach (['manager@example.com' => ['GAME_MANAGER'], 'owner@example.com' => ['OWNER']] as $email => $roles) {
            $this->signIn($email, $roles);

            foreach ($requests as [$method, $uri, $body]) {
                $this->client->jsonRequest($method, $uri, $body);

                self::assertResponseStatusCodeSame(403);
                self::assertSame(['error' => 'Access denied.'], $this->json());
            }
        }
    }

    #[Test]
    public function promotingAnNpcMakesACharacterOfItAndClosesTheNpc(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $npcId = $this->addNpc($id, ['name' => 'Mira', 'agenda' => 'Wants the ledger', 'tags' => ['crew'], 'freeTags' => ['Fence'], 'weight' => 3]);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/%s/promote', $id, $npcId), ['ignored' => true]);

        self::assertResponseStatusCodeSame(201);
        $character = $this->json();
        self::assertIsString($character['id'] ?? null);
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $character['id']);
        self::assertSame(['name' => 'Mira', 'tags' => ['crew'], 'freeTags' => ['Fence'], 'values' => self::DEFAULT_VALUES, 'derived' => [], 'promotedFrom' => $npcId], array_diff_key($character, ['id' => 1]));
        self::assertSame([$character], $this->campaignCharacters($id));
        self::assertSame([['id' => $npcId, 'name' => 'Mira', 'agenda' => 'Wants the ledger', 'tags' => ['crew'], 'freeTags' => ['Fence'], 'weight' => 3, 'status' => 'closed', 'promotedTo' => $character['id']]], $this->campaignNpcs($id));
    }

    #[Test]
    public function aClosedNpcCanBePromoted(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $npcId = $this->addNpc($id, ['name' => 'Mira']);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/%s/close', $id, $npcId));
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/%s/promote', $id, $npcId));

        self::assertResponseStatusCodeSame(201);
        self::assertSame($this->json()['id'] ?? null, $this->campaignNpcs($id)[0]['promotedTo'] ?? null);
    }

    #[Test]
    public function aPromotedNpcIsNotPromotedAgainNorReopenedButCanBeClosedAndEdited(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $npcId = $this->addNpc($id, ['name' => 'Mira']);
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/%s/promote', $id, $npcId));
        self::assertResponseStatusCodeSame(201);
        $promoted = $this->campaignNpcs($id);
        $characters = $this->campaignCharacters($id);

        foreach (['promote', 'reopen'] as $action) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/%s/%s', $id, $npcId, $action));

            self::assertResponseStatusCodeSame(409);
            self::assertSame(['error' => ['promote' => \sprintf('NPC "%s" was already promoted to a Character.', $npcId), 'reopen' => \sprintf('NPC "%s" was promoted to a Character and stays closed.', $npcId)][$action]], $this->json());
            self::assertSame([$promoted, $characters], [$this->campaignNpcs($id), $this->campaignCharacters($id)]);
        }

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/%s/close', $id, $npcId));
        self::assertResponseStatusCodeSame(200);
        self::assertSame($promoted[0], $this->json());

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/npcs/%s', $id, $npcId), ['name' => 'Mira Vane']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Mira Vane', 'closed', $promoted[0]['promotedTo']], [$this->json()['name'] ?? null, $this->json()['status'] ?? null, $this->json()['promotedTo'] ?? null]);
    }

    #[Test]
    public function promotingAnUnknownMalformedOrForeignNpcIsNotFound(): void
    {
        $this->signIn('bob@example.com', ['SOLO_PLAYER']);
        $bobs = $this->createCampaign();
        $bobsNpc = $this->addNpc($bobs, ['name' => 'Bob']);
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        foreach ([self::UNKNOWN_ID, 'not-a-uuid', ' '] as $npcId) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/%s/promote', $id, rawurlencode($npcId)));

            self::assertResponseStatusCodeSame(404);
            self::assertSame(['error' => \sprintf('NPC "%s" not found.', $npcId)], $this->json());
        }

        foreach ([[$bobs, $bobsNpc], [self::UNKNOWN_ID, $bobsNpc]] as [$campaignId, $npcId]) {
            $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/%s/promote', $campaignId, $npcId));

            self::assertResponseStatusCodeSame(404);
            self::assertSame(['error' => \sprintf('Campaign "%s" not found.', $campaignId)], $this->json());
        }

        self::assertSame([], $this->campaignCharacters($id));
        self::assertSame(['open', null], $this->connection()->fetchNumeric("SELECT npcs->0->>'status', npcs->0->>'promotedTo' FROM play_campaign WHERE id = ?", [$bobs]));
    }

    #[Test]
    public function aCampaignAtTheLimitRefusesAPromotionAndTheNpcStaysOpen(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $npcId = $this->addNpc($id, ['name' => 'Mira']);
        $characters = array_map(static fn (int $i): array => ['id' => \sprintf('c-%d', $i), 'name' => 'Character '.$i, 'tags' => [], 'freeTags' => [], 'values' => [], 'promotedFrom' => null], range(1, 100));
        $this->connection()->executeStatement('UPDATE play_campaign SET characters = ? WHERE id = ?', [json_encode($characters, \JSON_THROW_ON_ERROR), $id]);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/%s/promote', $id, $npcId));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'A campaign holds at most 100 Characters.'], $this->json());
        self::assertSame(['open', null], [$this->campaignNpcs($id)[0]['status'] ?? null, $this->campaignNpcs($id)[0]['promotedTo'] ?? null]);
        self::assertCount(100, $this->campaignCharacters($id));
    }

    #[Test]
    public function aGeneratedCharacterIdThatIsAlreadyTakenIsAConflictWhenPromoting(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        // Set before the first NPC request: the controller takes the generator when it is built.
        self::getContainer()->set(CharacterIdGenerator::class, new readonly class implements CharacterIdGenerator {
            public function generate(): CharacterId
            {
                return CharacterId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001');
            }
        });
        $npcId = $this->addNpc($id, ['name' => 'Mira']);
        $this->addCharacter($id, ['name' => 'Vi']);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/%s/promote', $id, $npcId));

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'Character "0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f7001" already exists.'], $this->json());
        self::assertSame(['open', ['Vi']], [$this->campaignNpcs($id)[0]['status'] ?? null, array_column($this->campaignCharacters($id), 'name')]);
    }

    #[Test]
    public function aPromotionOnACampaignSavedByAnotherRequestMeanwhileIsAConflict(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $npcId = $this->addNpc($id, ['name' => 'Mira']);

        $this->requestWhileAnotherRequestSaves($id, 'POST', \sprintf('/api/campaigns/%s/npcs/%s/promote', $id, $npcId), []);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => \sprintf('Campaign "%s" was changed by another request. Reload it and try again.', $id)], $this->json());
        self::assertSame(['open', []], [$this->campaignNpcs($id)[0]['status'] ?? null, $this->campaignCharacters($id)]);
    }

    #[Test]
    #[DataProvider('unreadableReleases')]
    public function aPromotionOnAPinnedReleaseThatCannotBeReadIsAServerErrorWithoutInternals(string $breakTheRelease): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();
        $npcId = $this->addNpc($id, ['name' => 'Mira']);
        $this->connection()->executeStatement($breakTheRelease);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs/%s/promote', $id, $npcId));

        self::assertResponseStatusCodeSame(500);
        self::assertSame(['error' => 'The GameSystem release this campaign plays can no longer be read.'], $this->json());
        self::assertSame(0, $this->connection()->fetchOne('SELECT jsonb_array_length(characters) FROM play_campaign WHERE id = ?', [$id]));
    }

    #[Test]
    public function promotingIsForSoloPlayersOnly(): void
    {
        $uri = \sprintf('/api/campaigns/%s/npcs/%s/promote', self::UNKNOWN_ID, self::UNKNOWN_ID);

        $this->client->jsonRequest('POST', $uri);
        self::assertResponseStatusCodeSame(401);

        foreach (['manager@example.com' => ['GAME_MANAGER'], 'owner@example.com' => ['OWNER']] as $email => $roles) {
            $this->signIn($email, $roles);

            $this->client->jsonRequest('POST', $uri);

            self::assertResponseStatusCodeSame(403);
            self::assertSame(['error' => 'Access denied.'], $this->json());
        }
    }

    #[Test]
    public function aCharacterCreatedByRollGetsTheRolledValuesAndAFullTrack(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign(CreationRelease::KEY);
        // Strength 3d6 = 4+5+6 -> 75, Constitution 2d6 = 3+4 -> 65, Luck 3d6 = 1+1+2 -> 20.
        $this->random->script(4, 5, 6, 3, 4, 1, 1, 2);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi', 'method' => 'roll', 'values' => ['role' => 'Spy']]);

        self::assertResponseStatusCodeSame(201);
        $character = $this->json();
        self::assertSame(['role' => 'Spy', 'str' => 75, 'con' => 65, 'luck' => 20, 'hp' => ['current' => 70, 'max' => 70]], $character['values'] ?? null);
        self::assertSame(['half' => 70], $character['derived'] ?? null);
        self::assertSame([$character], $this->campaignCharacters($id));
    }

    #[Test]
    public function aCharacterCreatedByPointsKeepsTheSpentValuesAndTheServerRollsTheRest(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign(CreationRelease::KEY);
        // Only Luck is rolled: 3+3+3 -> 45.
        $this->random->script(3, 3, 3);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi', 'method' => 'points', 'values' => ['str' => 40, 'con' => 60]]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['role' => '', 'str' => 40, 'con' => 60, 'luck' => 45, 'hp' => ['current' => 50, 'max' => 50]], $this->json()['values'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string, string}> the body, the campaign's game system, the code and the message
     */
    public static function refusedCreations(): iterable
    {
        yield 'no method on a sheet that offers both' => [['name' => 'Vi'], CreationRelease::KEY, 'creation_method_required', 'Choose how to create the Character: "roll" or "points".'];
        yield 'points on a sheet without a budget' => [['name' => 'Vi', 'method' => 'points'], 'sheet', 'creation_method_unavailable', 'This sheet has no point budget to spend; create the Character without a method or by "roll".'];
        yield 'a value for a rolled field' => [['name' => 'Vi', 'method' => 'roll', 'values' => ['luck' => 50]], CreationRelease::KEY, 'value_set_at_creation', 'Field "luck" is set when the Character is created; send no value for it.'];
        yield 'a value for a rolled budget field when rolling' => [['name' => 'Vi', 'method' => 'roll', 'values' => ['str' => 50]], CreationRelease::KEY, 'value_set_at_creation', 'Field "str" is set when the Character is created; send no value for it.'];
        yield 'a value for a start max track' => [['name' => 'Vi', 'method' => 'points', 'values' => ['str' => 40, 'con' => 40, 'hp' => ['current' => 5, 'max' => 5]]], CreationRelease::KEY, 'value_set_at_creation', 'Field "hp" is set when the Character is created; send no value for it.'];
        yield 'a point value below the budget minimum' => [['name' => 'Vi', 'method' => 'points', 'values' => ['str' => 10, 'con' => 40]], CreationRelease::KEY, 'outside_creation_bounds', 'Field "str" of "Points" must be 15 to 90 when spending points, got 10.'];
        yield 'a point value above the budget maximum' => [['name' => 'Vi', 'method' => 'points', 'values' => ['str' => 40, 'con' => 91]], CreationRelease::KEY, 'outside_creation_bounds', 'Field "con" of "Points" must be 15 to 90 when spending points, got 91.'];
        yield 'a missing point value' => [['name' => 'Vi', 'method' => 'points', 'values' => ['str' => 40]], CreationRelease::KEY, 'outside_creation_bounds', 'Field "con" is part of "Points": give it a value.'];
        yield 'more points than the budget' => [['name' => 'Vi', 'method' => 'points', 'values' => ['str' => 50, 'con' => 51]], CreationRelease::KEY, 'point_budget_exceeded', '"Points" allows 100 points, 101 spent.'];
    }

    #[Test]
    public function byRollTheNonDiceBudgetFieldsOfAMixedBudgetAreBoundedButNotTotalled(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign(CreationRelease::MIXED_KEY);
        $url = \sprintf('/api/campaigns/%s/characters', $id);
        $this->random->script(4, 5, 6, 4, 5, 6);

        $this->client->jsonRequest('POST', $url, ['name' => 'Vi', 'method' => 'roll', 'values' => ['san' => 95]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'Field "san" of "Points" must be 15 to 90 when creating by roll, got 95.', 'code' => 'outside_creation_bounds'], $this->json());
        self::assertSame([], $this->campaignCharacters($id));

        // 75 + 90 is over the 100 points, which rolling does not count.
        $this->client->jsonRequest('POST', $url, ['name' => 'Vi', 'method' => 'roll', 'values' => ['san' => 90]]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['str' => 75, 'san' => 90], $this->json()['values'] ?? null);
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('refusedCreations')]
    public function aBrokenCreationRuleIsUnprocessableWithItsCodeAndChangesNothing(array $body, string $gameSystemKey, string $code, string $message): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign($gameSystemKey);
        $this->random->script(1, 1, 1, 1, 1, 1, 1, 1);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => $message, 'code' => $code], $this->json());
        self::assertSame([], $this->campaignCharacters($id));
    }

    #[Test]
    public function aMethodTheServerDoesNotKnowIsUnprocessableAndOneOfTheWrongTypeIsMalformed(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign(CreationRelease::KEY);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi', 'method' => 'dice']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'The creation method is "roll" or "points".'], $this->json());

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi', 'method' => 7]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->campaignCharacters($id));
    }

    #[Test]
    public function aSheetWithoutCreationRulesIgnoresAMethodOfRollAndCreatesAsBefore(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign();

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $id), ['name' => 'Vi', 'method' => 'roll']);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(self::DEFAULT_VALUES, $this->json()['values'] ?? null);
    }

    #[Test]
    public function editingACharacterStillAcceptsAnyValueOfARolledField(): void
    {
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
        $id = $this->createCampaign(CreationRelease::KEY);
        $this->random->script(4, 5, 6, 3, 4, 1, 1, 2);
        $characterId = $this->addCharacter($id, ['name' => 'Vi', 'method' => 'roll']);

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/characters/%s', $id, $characterId), ['name' => 'Vi', 'values' => ['str' => 99, 'con' => 1, 'luck' => 7, 'hp' => ['current' => 3, 'max' => 90]]]);

        self::assertResponseIsSuccessful();
        self::assertSame(['role' => '', 'str' => 99, 'con' => 1, 'luck' => 7, 'hp' => ['current' => 3, 'max' => 90]], $this->json()['values'] ?? null);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requestWhileAnotherRequestSaves(string $campaignId, string $method, string $uri, array $body): void
    {
        // Another request saves the campaign after this one loaded it, right before it flushes.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $concurrentSave = new readonly class($this->connection(), $campaignId) {
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
            $this->client->jsonRequest($method, $uri, $body);
        } finally {
            $entityManager->getEventManager()->removeEventListener([Events::preFlush], $concurrentSave);
        }
    }

    /**
     * @return list<array<string, mixed>> the Characters of the campaign as GET /api/campaigns/{id} shows them
     */
    private function campaignCharacters(string $id): array
    {
        $this->client->request('GET', '/api/campaigns/'.$id);
        $characters = $this->json()['characters'] ?? null;
        self::assertIsArray($characters);

        /** @var list<array<string, mixed>> $list */
        $list = array_values($characters);

        return $list;
    }

    /**
     * @return list<array<string, mixed>> the NPCs of the campaign as GET /api/campaigns/{id} shows them
     */
    private function campaignNpcs(string $id): array
    {
        $this->client->request('GET', '/api/campaigns/'.$id);
        $npcs = $this->json()['npcs'] ?? null;
        self::assertIsArray($npcs);

        /** @var list<array<string, mixed>> $list */
        $list = array_values($npcs);

        return $list;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function addNpc(string $campaignId, array $body): string
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/npcs', $campaignId), $body);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function addCharacter(string $campaignId, array $body): string
    {
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/characters', $campaignId), $body);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function createCampaign(string $gameSystemKey = 'sheet'): string
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => $gameSystemKey]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param list<string> $roles
     */
    private function signIn(string $email, array $roles): void
    {
        $container = self::getContainer();
        $id = $container->get(UserIdGenerator::class)->generate()->toString();
        $container->get(CommandBus::class)->dispatch(new CreateUser($id, $email, self::PASSWORD, $roles));

        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
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
