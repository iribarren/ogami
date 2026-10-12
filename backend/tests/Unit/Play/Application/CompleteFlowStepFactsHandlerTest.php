<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignJournal;
use App\Play\Application\CompleteFlowStep;
use App\Play\Application\CompleteFlowStepHandler;
use App\Play\Application\OwnedCampaigns;
use App\Play\Domain\Campaign\Campaign;
use App\Play\Domain\Campaign\CampaignId;
use App\Play\Domain\Campaign\Facts\FactAlreadyExists;
use App\Play\Domain\Campaign\Lists\GeneratedItemId;
use App\Play\Domain\Campaign\PinnedRelease;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Tests\Support\Play\FixedClock;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use App\Tests\Support\Play\InMemoryJournalEntryRepository;
use App\Tests\Support\Play\InMemoryPublishedGameSystemReleases;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * fillFact Effects through the handler, on valid/v2-campaign-facts played by one Flow: a completed
 * step fills its slots and journals its answer (the fill has no entry of its own), and a refused
 * fill leaves the campaign and the journal as they were.
 */
#[CoversClass(CompleteFlowStepHandler::class)]
final class CompleteFlowStepFactsHandlerTest extends FlowRunHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $content = ReleaseViews::fixtureContent('valid/v2-campaign-facts');
        $content['flows'] = [['key' => 'facts', 'name' => 'Facts', 'defaultView' => 'journal', 'oracles' => [], 'trackers' => [], 'phases' => [
            ['key' => 'roam', 'name' => 'Roaming', 'mode' => 'loop', 'selection' => ['rule' => 'player', 'sceneTypes' => ['scene']]],
        ]]];
        $this->release = new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content));
        $this->campaigns = new InMemoryCampaignRepository();
        $this->entries = new InMemoryJournalEntryRepository();
        $this->releases = new InMemoryPublishedGameSystemReleases();
        $this->releases->add($this->release);
        $this->clock = new FixedClock(self::NOW);
        $this->ownedCampaigns = new OwnedCampaigns($this->campaigns);
        $this->journal = new CampaignJournal($this->ownedCampaigns, $this->entries, $this->releases, $this->clock);
        $campaign = Campaign::create(CampaignId::fromString('campaign-1'), 'user-1', 'Chronicle', PinnedRelease::of('campaign-facts', 1, 'Campaign facts'), self::at('09:00'), [], $this->release->flow('facts'));
        $campaign->startSession(self::at('09:05'), $this->release);
        $this->campaigns->add($campaign);
    }

    #[Test]
    public function aCompletedStepFillsItsSlotAndJournalsOnlyTheAnswer(): void
    {
        $this->complete('city', 'Prague');

        $campaign = $this->stored('campaign-1');
        self::assertSame(['Prague', 'prince'], [$campaign->factValues($this->release)['city'], $campaign->flowRun()?->stepKey()]);
        self::assertSame(GeneratedItemId::fact('campaign-1', 0)->toString(), $campaign->facts($this->release)[0]->id()->toString());
        self::assertSame(['kind' => 'note', 'text' => 'Prague'], $this->onlyEntryOf('campaign-1')->content()->toArray());
    }

    #[Test]
    public function aGeneratedFactIdAlreadyHeldRefusesTheStepAndChangesNothing(): void
    {
        $campaign = $this->stored('campaign-1');
        $campaign->addFreeFact(GeneratedItemId::fact('campaign-1', 1), 'Squatter', 'Text', [], $this->release);
        $this->campaigns->save($campaign);
        $before = unserialize(serialize($this->stored('campaign-1')));

        try {
            $this->complete('city', 'Prague');
            self::fail('The collision was accepted.');
        } catch (FactAlreadyExists) {
        }

        self::assertEquals($before, $this->stored('campaign-1'));
        self::assertSame([], $this->journalOf('campaign-1'));
    }

    private function complete(string $stepKey, string $text): void
    {
        $handler = new CompleteFlowStepHandler($this->journal, $this->campaigns, new ScriptedRandomNumberGenerator(), $this->clock);
        $handler(new CompleteFlowStep('campaign-1', 'user-1', 'entry-1', $stepKey, $text, null, null, null, null));
    }
}
