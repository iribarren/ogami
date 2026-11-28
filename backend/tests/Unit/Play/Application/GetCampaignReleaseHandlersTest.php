<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignNotFound;
use App\Play\Application\CardImageBytes;
use App\Play\Application\CardImageNotFound;
use App\Play\Application\GetCampaignCardImage;
use App\Play\Application\GetCampaignCardImageHandler;
use App\Play\Application\GetCampaignRelease;
use App\Play\Application\GetCampaignReleaseHandler;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\PublishedReleaseContents;
use App\Play\Application\ReleaseCardImageView;
use App\Play\Application\ReleaseContentView;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A campaign reads the release it is pinned to, never the latest one (ADR 0014), and only its owner
 * does. The release content and image bytes come from the PublishedReleaseContents port.
 */
#[CoversClass(GetCampaignReleaseHandler::class)]
#[CoversClass(GetCampaignCardImageHandler::class)]
#[CoversClass(GetCampaignRelease::class)]
#[CoversClass(GetCampaignCardImage::class)]
final class GetCampaignReleaseHandlersTest extends TestCase
{
    private const string BACK = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private OwnedCampaigns $owned;
    private PublishedReleaseContents $contents;

    protected function setUp(): void
    {
        $campaigns = new InMemoryCampaignRepository();
        $campaigns->add(Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The lost mine', PinnedRelease::of('mine', 1, 'Mine'), new \DateTimeImmutable('2026-11-28T09:00:00+00:00')));
        $this->owned = new OwnedCampaigns($campaigns);
        $this->contents = new class implements PublishedReleaseContents {
            public function content(string $gameSystemKey, int $version): ReleaseContentView
            {
                if ('mine' !== $gameSystemKey || !\in_array($version, [1, 2], true)) {
                    throw GameSystemReleaseNotFound::for($gameSystemKey, $version);
                }

                return new ReleaseContentView($gameSystemKey, $version, new \DateTimeImmutable('2026-11-27T10:00:00+00:00'), ['schemaVersion' => 2], 1 === $version ? [new ReleaseCardImageView('cardBack', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'image/png', 815, 1110)] : []);
            }

            public function cardImage(string $gameSystemKey, int $version, string $sha256): CardImageBytes
            {
                if (1 !== $version || 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' !== $sha256) {
                    throw CardImageNotFound::withHash($sha256);
                }

                return new CardImageBytes('image/png', 'bytes');
            }
        };
    }

    #[Test]
    public function theOwnerReadsThePinnedReleaseContentWithItsImages(): void
    {
        $view = (new GetCampaignReleaseHandler($this->owned, $this->contents))(new GetCampaignRelease('user-1', 'campaign-1'));

        self::assertSame(['mine', 1, ['schemaVersion' => 2]], [$view->gameSystemKey, $view->version, $view->content]);
        self::assertEquals([new ReleaseCardImageView('cardBack', self::BACK, 'image/png', 815, 1110)], $view->cardImages);
    }

    #[Test]
    public function theOwnerReadsAPinnedImage(): void
    {
        $image = (new GetCampaignCardImageHandler($this->owned, $this->contents))(new GetCampaignCardImage('user-1', 'campaign-1', self::BACK));

        self::assertSame(['image/png', 'bytes'], [$image->mediaType, $image->bytes]);
    }

    #[Test]
    public function anImageTheReleaseDoesNotPinIsNotFound(): void
    {
        $this->expectException(CardImageNotFound::class);

        (new GetCampaignCardImageHandler($this->owned, $this->contents))(new GetCampaignCardImage('user-1', 'campaign-1', str_repeat('b', 64)));
    }

    #[Test]
    public function anotherPlayersOrAnUnknownCampaignIsNotFound(): void
    {
        $notFound = 0;
        foreach ([['user-2', 'campaign-1'], ['user-1', 'campaign-9']] as [$user, $campaign]) {
            foreach ([
                static fn (OwnedCampaigns $owned, PublishedReleaseContents $contents): ReleaseContentView => (new GetCampaignReleaseHandler($owned, $contents))(new GetCampaignRelease($user, $campaign)),
                static fn (OwnedCampaigns $owned, PublishedReleaseContents $contents): CardImageBytes => (new GetCampaignCardImageHandler($owned, $contents))(new GetCampaignCardImage($user, $campaign, self::BACK)),
            ] as $read) {
                try {
                    $read($this->owned, $this->contents);
                    self::fail('The campaign is not '.$user.'\'s to read.');
                } catch (CampaignNotFound) {
                    ++$notFound;
                }
            }
        }

        self::assertSame(4, $notFound);
    }

    #[Test]
    public function aPinnedReleaseThatCannotBeFoundIsReportedAsSuch(): void
    {
        $campaigns = new InMemoryCampaignRepository();
        $campaigns->add(Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'Gone', PinnedRelease::of('mine', 9, 'Mine'), new \DateTimeImmutable('2026-11-28T09:00:00+00:00')));

        $this->expectException(GameSystemReleaseNotFound::class);

        (new GetCampaignReleaseHandler(new OwnedCampaigns($campaigns), $this->contents))(new GetCampaignRelease('user-1', 'campaign-2'));
    }
}
