<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play\Http;

use App\Identity\Application\CreateUser;
use App\Identity\Application\UserIdGenerator;
use App\Play\Infrastructure\Http\CampaignController;
use App\Play\Infrastructure\Http\PinnedReleaseResponse;
use App\Play\Infrastructure\Http\SheetFieldResponse;
use App\Play\Infrastructure\Http\SheetGroupResponse;
use App\Play\Infrastructure\Http\SheetLayoutResponse;
use App\Shared\Application\Bus\CommandBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The sheet layout (ADR 0031) over the Play API: a release that declares groups shows them, with
 * the span of each field; a release stored without a layout shows none and a span of 1.
 */
#[CoversClass(CampaignController::class)]
#[CoversClass(PinnedReleaseResponse::class)]
#[CoversClass(SheetFieldResponse::class)]
#[CoversClass(SheetLayoutResponse::class)]
#[CoversClass(SheetGroupResponse::class)]
final class CampaignSheetLayoutApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $bus = self::getContainer()->get(CommandBus::class);

        $legacy = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-sheet'), 'sceneTypes', []);
        $bus->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9111', $legacy, false));

        $laidOut = ReleaseArrays::with(ReleaseViews::fixtureContent('valid/v2-sheet'), 'sceneTypes', []);
        $laidOut = ReleaseArrays::with($laidOut, 'gameSystem.key', 'laid-out');
        $laidOut = ReleaseArrays::with($laidOut, 'sheet', [
            'columns' => 2,
            'groups' => [['name' => 'Stats', 'columns' => 2, 'span' => 2], ['name' => 'Notes'], ['name' => 'Empty']],
            'fields' => [
                ['key' => 'str', 'label' => 'Strength', 'type' => 'number', 'group' => 'Stats', 'span' => 2],
                ['key' => 'dex', 'label' => 'Dexterity', 'type' => 'number', 'group' => 'Stats'],
                ['key' => 'bio', 'label' => 'Bio', 'type' => 'text', 'group' => 'Notes'],
            ],
        ]);
        $bus->dispatch(new PublishGameSystemRelease('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f9112', $laidOut, false));

        $container = self::getContainer();
        $container->get(CommandBus::class)->dispatch(new CreateUser($container->get(UserIdGenerator::class)->generate()->toString(), 'ada@example.com', 'secret123', ['SOLO_PLAYER']));
        $this->client->jsonRequest('POST', '/api/auth/login', ['email' => 'ada@example.com', 'password' => 'secret123']);
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function aReleaseWithALayoutShowsItsGroupsInDeclaredOrderAndTheSpanOfEachField(): void
    {
        $pinned = $this->pinnedRelease('laid-out');

        self::assertSame(
            ['columns' => 2, 'groups' => [['name' => 'Stats', 'columns' => 2, 'span' => 2], ['name' => 'Notes', 'columns' => 1, 'span' => 1], ['name' => 'Empty', 'columns' => 1, 'span' => 1]]],
            $pinned['sheetLayout'] ?? null,
        );
        self::assertSame([2, 1, 1], array_column((array) ($pinned['sheet'] ?? null), 'span'));
    }

    #[Test]
    public function aReleaseStoredWithoutALayoutShowsNoLayoutAndASpanOfOneOnEveryField(): void
    {
        $pinned = $this->pinnedRelease('sheet');

        self::assertArrayHasKey('sheetLayout', $pinned);
        self::assertNull($pinned['sheetLayout']);
        self::assertSame(array_fill(0, 9, 1), array_column((array) ($pinned['sheet'] ?? null), 'span'));
    }

    /**
     * @return array<mixed>
     */
    private function pinnedRelease(string $gameSystemKey): array
    {
        $this->client->jsonRequest('POST', '/api/campaigns', ['name' => 'The job', 'gameSystemKey' => $gameSystemKey]);
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($created);
        $id = $created['id'] ?? null;
        self::assertIsString($id);

        $this->client->request('GET', '/api/campaigns/'.$id);
        self::assertResponseIsSuccessful();
        $campaign = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($campaign);
        $pinned = $campaign['pinnedRelease'] ?? null;
        self::assertIsArray($pinned);

        return $pinned;
    }
}
