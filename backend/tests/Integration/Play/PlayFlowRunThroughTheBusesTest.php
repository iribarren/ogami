<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play;

use App\Play\Application\CompleteFlowStep;
use App\Play\Application\CreateCampaign;
use App\Play\Application\GetCampaign;
use App\Play\Application\GetJournal;
use App\Play\Application\PauseGuidance;
use App\Play\Application\PickSceneType;
use App\Play\Application\ResumeGuidance;
use App\Play\Application\StartSession;
use App\Play\Domain\Campaign\CampaignModifiedConcurrently;
use App\Play\Domain\Campaign\FlowRun\FlowRunNotActive;
use App\Play\Domain\Campaign\FlowRun\InvalidStepResult;
use App\Play\Infrastructure\Persistence\Doctrine\DoctrineCampaignRepository;
use App\Play\Infrastructure\Persistence\Doctrine\DoctrineJournalEntryRepository;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\ReleaseViews;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A solo player is guided through the Cyberpunk RED heist example (Flow "heist") by the real buses,
 * handlers and Doctrine repositories: a completed step persists its journal entry and the FlowRun
 * together, a refused one persists nothing. The entity manager is cleared after every message, as
 * between two requests.
 */
#[CoversClass(DoctrineCampaignRepository::class)]
#[CoversClass(DoctrineJournalEntryRepository::class)]
final class PlayFlowRunThroughTheBusesTest extends KernelTestCase
{
    private const string RELEASE_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a82';
    private const string PLAYER = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5101';
    private const string CAMPAIGN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5202';
    private const string ENTRY_1 = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5311';
    private const string ENTRY_2 = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5312';

    private CommandBus $commands;
    private QueryBus $queries;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $this->commands = $container->get(CommandBus::class);
        $this->queries = $container->get(QueryBus::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->dispatch(new PublishGameSystemRelease(self::RELEASE_ID, ReleaseViews::fixtureContent('examples/cpr-heist'), false));
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, self::PLAYER, 'The job', 'cpr-heist', 'heist'));
        $this->dispatch(new StartSession(self::CAMPAIGN, self::PLAYER));
        $this->dispatch(new PickSceneType(self::CAMPAIGN, self::PLAYER, 'crew'));
    }

    #[Test]
    public function aCompletedStepPersistsItsJournalEntryAndTheFlowRunTogether(): void
    {
        $this->dispatch(new CompleteFlowStep(self::CAMPAIGN, self::PLAYER, self::ENTRY_1, 'first', 'Ada, a netrunner', null, null, null));

        $journal = $this->queries->ask(new GetJournal(self::CAMPAIGN, self::PLAYER));
        self::assertCount(1, $journal);
        self::assertSame([self::ENTRY_1, 1, 1, 'note', ['kind' => 'note', 'text' => 'Ada, a netrunner']], [$journal[0]->id, $journal[0]->sessionNumber, $journal[0]->sceneNumber, $journal[0]->kind, $journal[0]->content]);
        self::assertSame('first', $journal[0]->flowStep['key'] ?? null);
        $campaign = $this->queries->ask(new GetCampaign(self::CAMPAIGN, self::PLAYER));
        self::assertSame('second', $campaign->flowRun?->step?->step->key);
    }

    #[Test]
    public function aRefusedStepPersistsNothing(): void
    {
        $this->dispatch(new CompleteFlowStep(self::CAMPAIGN, self::PLAYER, self::ENTRY_1, 'first', 'Ada, a netrunner', null, null, null));

        $this->dispatchRefused(new CompleteFlowStep(self::CAMPAIGN, self::PLAYER, self::ENTRY_2, 'second', null, 'left', null, null), InvalidStepResult::class);

        self::assertCount(1, $this->queries->ask(new GetJournal(self::CAMPAIGN, self::PLAYER)));
        self::assertSame('second', $this->queries->ask(new GetCampaign(self::CAMPAIGN, self::PLAYER))->flowRun?->step?->step->key);
    }

    #[Test]
    public function aStepCompletedWhileAnotherRequestSavedTheCampaignLeavesNoJournalEntryBehind(): void
    {
        $before = $this->queries->ask(new GetCampaign(self::CAMPAIGN, self::PLAYER))->flowRun;
        $this->entityManager->getEventManager()->addEventListener(Events::preFlush, $this->anotherRequestSavesTheCampaign());

        $this->dispatchRefused(new CompleteFlowStep(self::CAMPAIGN, self::PLAYER, self::ENTRY_1, 'first', 'Ada, a netrunner', null, null, null), CampaignModifiedConcurrently::class);

        self::assertSame([], $this->queries->ask(new GetJournal(self::CAMPAIGN, self::PLAYER)));
        self::assertEquals($before, $this->queries->ask(new GetCampaign(self::CAMPAIGN, self::PLAYER))->flowRun);
    }

    #[Test]
    public function pausedGuidanceRefusesStepsAndResumesWhereItStopped(): void
    {
        $this->dispatch(new PauseGuidance(self::CAMPAIGN, self::PLAYER));
        $this->dispatchRefused(new CompleteFlowStep(self::CAMPAIGN, self::PLAYER, self::ENTRY_1, 'first', 'Ada', null, null, null), FlowRunNotActive::class);
        self::assertSame([], $this->queries->ask(new GetJournal(self::CAMPAIGN, self::PLAYER)));

        $this->dispatch(new ResumeGuidance(self::CAMPAIGN, self::PLAYER));

        self::assertSame('first', $this->queries->ask(new GetCampaign(self::CAMPAIGN, self::PLAYER))->flowRun?->step?->step->key);
    }

    /**
     * A flush listener that, once, bumps the stored version of the campaign being flushed, as another
     * request saving it in between would: the optimistic lock of this request then fails.
     */
    private function anotherRequestSavesTheCampaign(): object
    {
        return new class($this->entityManager) {
            private bool $done = false;

            public function __construct(private readonly EntityManagerInterface $entityManager)
            {
            }

            public function preFlush(): void
            {
                if ($this->done) {
                    return;
                }

                $this->done = true;
                $this->entityManager->getConnection()->executeStatement('UPDATE play_campaign SET version = version + 1');
            }
        };
    }

    /**
     * @param class-string<\Throwable> $error
     */
    private function dispatchRefused(Command $command, string $error): void
    {
        try {
            $this->dispatch($command);
            self::fail('The command was accepted.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf($error, $exception);
            $this->entityManager->clear();
        }
    }

    private function dispatch(Command $command): void
    {
        $this->commands->dispatch($command);
        $this->entityManager->clear();
    }
}
