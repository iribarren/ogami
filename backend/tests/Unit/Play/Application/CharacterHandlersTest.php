<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Formula\Domain\Formula;
use App\Play\Application\AddCharacter;
use App\Play\Application\AddCharacterHandler;
use App\Play\Application\CharacterView;
use App\Play\Application\EditCharacter;
use App\Play\Application\EditCharacterHandler;
use App\Play\Application\GetCampaign;
use App\Play\Application\GetCampaignHandler;
use App\Play\Application\OwnedCampaigns;
use App\Play\Application\PromoteNpc;
use App\Play\Application\PromoteNpcHandler;
use App\Play\Application\SheetFieldView;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Characters\Character;
use App\Play\Domain\Campaign\Characters\CharacterId;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\Campaign\Characters\UnknownCharacter;
use App\Play\Domain\Campaign\Lists\NpcAlreadyPromoted;
use App\Play\Domain\Campaign\Lists\NpcId;
use App\Play\Domain\Campaign\Lists\UnknownNpc;
use App\Play\Domain\Campaign\Lists\UnknownReleaseTag;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Domain\GameSystem\GameSystemReleaseNotFound;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\Sheet\ListField;
use App\Play\Domain\GameSystem\Sheet\NumberField;
use App\Play\Domain\GameSystem\Sheet\PointBudget;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use App\Play\Domain\GameSystem\Sheet\TextField;
use App\Play\Domain\GameSystem\Sheet\TrackField;
use App\Play\Domain\GameSystem\Tag;
use App\Randomness\Domain\DiceExpression;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\UnreadablePublishedGameSystemReleases;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AddCharacter::class)]
#[CoversClass(AddCharacterHandler::class)]
#[CoversClass(EditCharacter::class)]
#[CoversClass(EditCharacterHandler::class)]
#[CoversClass(PromoteNpc::class)]
#[CoversClass(PromoteNpcHandler::class)]
#[CoversClass(CharacterView::class)]
#[CoversClass(SheetFieldView::class)]
#[CoversClass(GetCampaignHandler::class)]
final class CharacterHandlersTest extends TestCase
{
    private InMemoryCampaignRepository $campaigns;
    private InMemoryPublishedGameSystemReleases $releases;
    private AddCharacterHandler $add;
    private EditCharacterHandler $edit;

    protected function setUp(): void
    {
        $this->releases = new InMemoryPublishedGameSystemReleases();
        $this->releases->add(new GameSystemSnapshot('heist', 'Heist', 1, null, [], [], tags: [new Tag('ally', 'Ally')], sheet: new SheetTemplate([
            new NumberField('reflexes', 'Reflexes', min: 1, max: 10, default: 4),
            new TrackField('hp', 'Hit points', max: 40),
            new TextField('role', 'Role', 'What you do.', 'Identity'),
            new ListField('gear', 'Gear', itemLabel: 'Item', maxItems: 5),
        ])));
        // A newer release with another sheet: the commands follow the pinned v1.
        $this->releases->add(new GameSystemSnapshot('heist', 'Heist, revised', 2, null, [], [], tags: [new Tag('boss', 'Boss')], sheet: new SheetTemplate([new NumberField('luck', 'Luck')])));
        $this->campaigns = new InMemoryCampaignRepository();
        $this->campaigns->add(Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00')));
        $owned = new OwnedCampaigns($this->campaigns);
        $this->add = new AddCharacterHandler($owned, $this->campaigns, $this->releases, new ScriptedRandomNumberGenerator());
        $this->edit = new EditCharacterHandler($owned, $this->campaigns, $this->releases);
    }

    #[Test]
    public function itAddsACharacterAndChecksItAgainstThePinnedRelease(): void
    {
        ($this->add)(new AddCharacter('campaign-1', 'user-1', 'c-1', ' Vi ', ['ally'], ['Fixer'], ['reflexes' => 7]));

        $character = $this->character('c-1');
        self::assertSame(['Vi', ['ally'], ['Fixer'], ['reflexes' => 7, 'hp' => ['current' => 40, 'max' => 40], 'role' => '', 'gear' => []]], [$character?->name(), $character?->tags(), $character?->freeTags(), $character?->values()]);
    }

    #[Test]
    public function aTagOrAFieldOfAnotherReleaseThanThePinnedOneIsRefusedAndNothingChanges(): void
    {
        foreach ([[['boss'], [], UnknownReleaseTag::class], [[], ['luck' => 1], InvalidCharacter::class]] as [$tags, $values, $error]) {
            try {
                ($this->add)(new AddCharacter('campaign-1', 'user-1', 'c-1', 'Vi', $tags, [], $values));
                self::fail('The Character was accepted.');
            } catch (\DomainException $refused) {
                self::assertInstanceOf($error, $refused);
            }

            self::assertSame([], $this->campaign()->characters());
        }
    }

    #[Test]
    public function itReplacesTheEditableFieldsAndResetsTheValuesNotGiven(): void
    {
        ($this->add)(new AddCharacter('campaign-1', 'user-1', 'c-1', 'Vi', ['ally'], [], ['reflexes' => 7, 'role' => 'Solo']));

        ($this->edit)(new EditCharacter('campaign-1', 'user-1', 'c-1', 'Violet', [], ['Fixer'], ['hp' => ['current' => 3]]));

        $character = $this->character('c-1');
        self::assertSame(['Violet', [], ['Fixer'], ['reflexes' => 4, 'hp' => ['current' => 3, 'max' => 40], 'role' => '', 'gear' => []]], [$character?->name(), $character?->tags(), $character?->freeTags(), $character?->values()]);
    }

    #[Test]
    public function anInvalidEditChangesNothing(): void
    {
        ($this->add)(new AddCharacter('campaign-1', 'user-1', 'c-1', 'Vi', [], [], ['reflexes' => 7]));

        $this->expectException(InvalidCharacter::class);

        try {
            ($this->edit)(new EditCharacter('campaign-1', 'user-1', 'c-1', 'Violet', [], [], ['reflexes' => 11]));
        } finally {
            self::assertSame(['Vi', 7], [$this->character('c-1')?->name(), $this->character('c-1')?->values()['reflexes'] ?? null]);
        }
    }

    #[Test]
    public function anUnknownCharacterIsNotFoundBeforeTheReleaseIsRead(): void
    {
        $handler = new EditCharacterHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, new UnreadablePublishedGameSystemReleases(GameSystemReleaseNotFound::for('heist', 1)));

        $this->expectException(UnknownCharacter::class);

        $handler(new EditCharacter('campaign-1', 'user-1', 'missing', 'Vi', [], [], []));
    }

    #[Test]
    public function theCampaignViewShowsTheSheetOfThePinnedReleaseAndTheCharactersValuesInFieldOrder(): void
    {
        $campaign = $this->campaign();
        $campaign->addCharacter(CharacterId::fromString('c-1'), 'Vi', [], [], [], $this->releases->get('heist', 1));
        // The database keeps JSONB keys in its own order; the view puts them back in the sheet's.
        $stored = Character::of(CharacterId::fromString('c-2'), 'Mira', [], [], ['role' => 'Solo', 'gear' => ['Knife'], 'hp' => ['current' => 1, 'max' => 40], 'reflexes' => 3]);
        $campaigns = new InMemoryCampaignRepository();
        $campaigns->add(Campaign::reconstitute(CampaignId::fromString('campaign-1'), 'user-1', 'The job', PinnedRelease::of('heist', 1, 'Heist'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00'), [], characters: [...$campaign->characters(), $stored]));

        $view = new GetCampaignHandler(new OwnedCampaigns($campaigns), $this->releases)(new GetCampaign('campaign-1', 'user-1'));

        self::assertEquals([
            new SheetFieldView('reflexes', 'Reflexes', null, null, 'number', 1, 10, 4),
            new SheetFieldView('hp', 'Hit points', null, null, 'track', max: 40),
            new SheetFieldView('role', 'Role', 'What you do.', 'Identity', 'text', multiline: false, maxLength: 200),
            new SheetFieldView('gear', 'Gear', null, null, 'list', itemLabel: 'Item', maxItems: 5),
        ], $view->pinnedRelease->sheet);
        self::assertSame(['Vi', 'Mira'], array_map(static fn (CharacterView $character): string => $character->name, $view->characters));
        self::assertSame(['reflexes', 'hp', 'role', 'gear'], array_keys($view->characters[1]->values));
    }

    #[Test]
    public function itPromotesAnNpcAgainstThePinnedReleaseAndSavesBothSides(): void
    {
        $this->campaignWithNpc();

        (new PromoteNpcHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, $this->releases, new ScriptedRandomNumberGenerator()))(new PromoteNpc('campaign-1', 'user-1', 'npc-1', 'c-1'));

        $character = $this->character('c-1');
        self::assertSame(['Mira', ['ally'], ['Fence'], ['reflexes' => 4, 'hp' => ['current' => 40, 'max' => 40], 'role' => '', 'gear' => []], 'npc-1'], [$character?->name(), $character?->tags(), $character?->freeTags(), $character?->values(), $character?->promotedFrom()?->toString()]);
        self::assertSame('c-1', $this->campaign()->npc(NpcId::fromString('npc-1'))?->promotedTo()?->toString());
    }

    #[Test]
    public function aPromotedNpcIsRefusedASecondPromotionAndNothingChanges(): void
    {
        $this->campaignWithNpc();
        $promote = new PromoteNpcHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, $this->releases, new ScriptedRandomNumberGenerator());
        $promote(new PromoteNpc('campaign-1', 'user-1', 'npc-1', 'c-1'));

        try {
            $promote(new PromoteNpc('campaign-1', 'user-1', 'npc-1', 'c-2'));
            self::fail('The second promotion was accepted.');
        } catch (NpcAlreadyPromoted) {
            self::assertSame(['c-1'], array_map(static fn (Character $character): string => $character->id()->toString(), $this->campaign()->characters()));
        }
    }

    #[Test]
    public function anUnknownNpcIsNotFoundBeforeTheReleaseIsRead(): void
    {
        $handler = new PromoteNpcHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, new UnreadablePublishedGameSystemReleases(GameSystemReleaseNotFound::for('heist', 1)), new ScriptedRandomNumberGenerator());

        $this->expectException(UnknownNpc::class);

        $handler(new PromoteNpc('campaign-1', 'user-1', 'missing', 'c-1'));
    }

    #[Test]
    public function anUnreadablePinnedReleaseFailsThePromotionAndNothingChanges(): void
    {
        $this->campaignWithNpc();
        $handler = new PromoteNpcHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, new UnreadablePublishedGameSystemReleases(GameSystemReleaseNotFound::for('heist', 1)), new ScriptedRandomNumberGenerator());

        try {
            $handler(new PromoteNpc('campaign-1', 'user-1', 'npc-1', 'c-1'));
            self::fail('The promotion was accepted.');
        } catch (GameSystemReleaseNotFound) {
            self::assertSame([[], false], [$this->campaign()->characters(), $this->campaign()->npc(NpcId::fromString('npc-1'))?->isPromoted()]);
        }
    }

    #[Test]
    public function itCreatesByRollOrByPointsWithTheInjectedRandomSourceAndSavesTheCharacter(): void
    {
        $owned = new OwnedCampaigns($this->campaigns);
        $this->releases->add($this->creationRelease());
        $this->campaigns->add(Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'Rolls', PinnedRelease::of('rolls', 1, 'Rolls'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00')));
        // str 3d6 = 2+3+4 -> 45, luck 1d6 = 6 -> 6; hp starts full at str.
        $add = new AddCharacterHandler($owned, $this->campaigns, $this->releases, new ScriptedRandomNumberGenerator(2, 3, 4, 6, 2));

        $add(new AddCharacter('campaign-2', 'user-1', 'c-1', 'Vi', [], [], [], 'roll'));
        // By points only luck is rolled: 2.
        $add(new AddCharacter('campaign-2', 'user-1', 'c-2', 'Bo', [], [], ['str' => 30], 'points'));

        $campaign = $this->campaigns->ofId(CampaignId::fromString('campaign-2')) ?? throw new \LogicException('The campaign is there.');
        self::assertSame(['str' => 45, 'luck' => 6, 'hp' => ['current' => 45, 'max' => 45]], $campaign->character(CharacterId::fromString('c-1'))?->values());
        self::assertSame(['str' => 30, 'luck' => 2, 'hp' => ['current' => 30, 'max' => 30]], $campaign->character(CharacterId::fromString('c-2'))?->values());
    }

    #[Test]
    public function aRefusedCreationOrAnUnknownMethodSavesNothing(): void
    {
        $owned = new OwnedCampaigns($this->campaigns);
        $this->releases->add($this->creationRelease());
        $this->campaigns->add(Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'Rolls', PinnedRelease::of('rolls', 1, 'Rolls'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00')));
        $add = new AddCharacterHandler($owned, $this->campaigns, $this->releases, new ScriptedRandomNumberGenerator(1, 1, 1, 1));

        foreach ([[[], null, 'creation_method_required'], [['str' => 99], 'points', 'outside'], [[], 'dice', 'unknown']] as [$values, $method]) {
            try {
                $add(new AddCharacter('campaign-2', 'user-1', 'c-1', 'Vi', [], [], $values, $method));
                self::fail('The Character was accepted.');
            } catch (InvalidCharacter) {
                self::assertSame([], $this->campaigns->ofId(CampaignId::fromString('campaign-2'))?->characters());
            }
        }
    }

    #[Test]
    public function aPromotionRollsTheSheetWithTheInjectedRandomSource(): void
    {
        $this->releases->add($this->creationRelease());
        $campaign = Campaign::create(CampaignId::fromString('campaign-2'), 'user-1', 'Rolls', PinnedRelease::of('rolls', 1, 'Rolls'), new \DateTimeImmutable('2026-10-12T09:00:00+00:00'));
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, [], [], 1, $this->releases->get('rolls', 1));
        $this->campaigns->add($campaign);

        new PromoteNpcHandler(new OwnedCampaigns($this->campaigns), $this->campaigns, $this->releases, new ScriptedRandomNumberGenerator(2, 3, 4, 6))(new PromoteNpc('campaign-2', 'user-1', 'npc-1', 'c-1'));

        self::assertSame(['str' => 45, 'luck' => 6, 'hp' => ['current' => 45, 'max' => 45]], $this->campaigns->ofId(CampaignId::fromString('campaign-2'))?->character(CharacterId::fromString('c-1'))?->values());
    }

    private function creationRelease(): GameSystemSnapshot
    {
        return new GameSystemSnapshot('rolls', 'Rolls', 1, null, [], [], sheet: new SheetTemplate([
            new NumberField('str', 'Strength', min: 1, max: 99, roll: DiceExpression::fromString('3d6*5')),
            new NumberField('luck', 'Luck', min: 1, max: 6, roll: DiceExpression::fromString('1d6')),
            new TrackField('hp', 'Hit points', startMax: Formula::fromString('{str}')),
        ], budgets: [new PointBudget(50, ['str'], min: 15, max: 90)]));
    }

    private function campaignWithNpc(): void
    {
        $campaign = $this->campaign();
        $campaign->addNpc(NpcId::fromString('npc-1'), 'Mira', null, ['ally'], ['Fence'], 1, $this->releases->get('heist', 1));
        $this->campaigns->save($campaign);
    }

    private function character(string $id): ?Character
    {
        return $this->campaign()->character(CharacterId::fromString($id));
    }

    private function campaign(): Campaign
    {
        return $this->campaigns->ofId(CampaignId::fromString('campaign-1')) ?? throw new \LogicException('The campaign is there.');
    }
}
