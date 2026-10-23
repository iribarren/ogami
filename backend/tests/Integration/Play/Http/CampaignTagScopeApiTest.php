<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Infrastructure\Http\CampaignCharactersController;
use App\Play\Infrastructure\Http\CampaignNpcsController;
use App\Play\Infrastructure\Http\CampaignThreadsController;
use App\Play\Infrastructure\Http\TagResponse;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tags limited to some entity kinds over the Play API (ADR 0018, addendum of 2026-10-24), on
 * valid/v2-threads-npcs plus the Tags "villain" (NPCs only), "goal" (Threads only) and "hero"
 * (Characters only): the campaign shows each Tag's scope, and a Tag on another kind is a 422 like an
 * unknown one.
 */
#[CoversClass(CampaignNpcsController::class)]
#[CoversClass(CampaignThreadsController::class)]
#[CoversClass(CampaignCharactersController::class)]
#[CoversClass(TagResponse::class)]
final class CampaignTagScopeApiTest extends WebTestCase
{
    private const string PASSWORD = 'secret123';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $content = ReleaseViews::fixtureContent('valid/v2-threads-npcs');
        // Three more Tags that no flow step names, each for one kind.
        $content = ReleaseArrays::with($content, 'tags.3', ['key' => 'villain', 'label' => 'Villain', 'appliesTo' => ['npc']]);
        $content = ReleaseArrays::with($content, 'tags.4', ['key' => 'goal', 'label' => 'Goal', 'appliesTo' => ['thread']]);
        $content = ReleaseArrays::with($content, 'tags.5', ['key' => 'hero', 'label' => 'Hero', 'appliesTo' => ['character']]);
        self::getContainer()->get(CommandBus::class)->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9001', $content, false));
        $this->signIn('ada@example.com', ['SOLO_PLAYER']);
    }

    #[Test]
    public function theCampaignShowsEachTagsScopeAndNullForAnUnscopedOne(): void
    {
        $id = $this->createCampaign();

        self::assertSame([['leader', null], ['ally', null], ['hostile', null], ['villain', ['npc']], ['goal', ['thread']], ['hero', ['character']]], array_map(static fn (array $tag): array => [$tag['key'], $tag['appliesTo']], $this->campaignList($id, 'tags')));
    }

    /**
     * @return iterable<string, array{string, string}> the list path, the tag that is not for it
     */
    public static function tagsOnTheWrongKind(): iterable
    {
        yield 'an NPC with a Character tag' => ['npcs', 'hero'];
        yield 'a Thread with an NPC tag' => ['threads', 'villain'];
        yield 'a Character with a Thread tag' => ['characters', 'goal'];
    }

    #[Test]
    #[DataProvider('tagsOnTheWrongKind')]
    public function aTagOnAnotherKindIsUnprocessableAndNothingChanges(string $list, string $tag): void
    {
        $id = $this->createCampaign();
        $kind = ['npcs' => 'NPCs', 'threads' => 'Threads', 'characters' => 'Characters'][$list];
        $own = ['npcs' => 'villain', 'threads' => 'goal', 'characters' => 'hero'][$list];
        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/%s', $id, $list), ['name' => 'First', 'tags' => [$own]]);
        self::assertResponseStatusCodeSame(201);
        $item = $this->json()['id'];
        self::assertIsString($item);

        $this->client->jsonRequest('POST', \sprintf('/api/campaigns/%s/%s', $id, $list), ['name' => 'Second', 'tags' => [$own, $tag]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => \sprintf('Tag "%s" does not apply to %s.', $tag, $kind)], $this->json());

        $this->client->jsonRequest('PUT', \sprintf('/api/campaigns/%s/%s/%s', $id, $list, $item), ['name' => 'First', 'tags' => [$tag]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => \sprintf('Tag "%s" does not apply to %s.', $tag, $kind)], $this->json());

        $items = $this->campaignList($id, $list);
        self::assertCount(1, $items);
        self::assertSame([$own], $items[0]['tags']);
    }

    private function createCampaign(): string
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => 'threads-npcs']);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<array<string, mixed>> a list of GET /api/campaigns/{id}: tags, npcs, threads or characters
     */
    private function campaignList(string $id, string $name): array
    {
        $this->client->request('GET', '/api/campaigns/'.$id);
        self::assertResponseIsSuccessful();
        $items = $this->json()[$name] ?? null;
        self::assertIsArray($items);

        /** @var list<array<string, mixed>> $list */
        $list = array_values($items);

        return $list;
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
