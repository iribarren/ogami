<?php

declare(strict_types=1);

namespace App\Tests\Integration\Play;

use App\Play\Application\CreateCampaign;
use App\Play\Application\GetCampaign;
use App\Play\Application\GetJournal;
use App\Play\Application\JournalEntryView;
use App\Play\Application\ListMyCampaigns;
use App\Play\Application\RecordLikelihoodAnswer;
use App\Play\Application\RecordNote;
use App\Play\Application\RecordOracleTableResult;
use App\Play\Application\RecordRoll;
use App\Play\Application\StartScene;
use App\Play\Application\StartSession;
use App\Play\Infrastructure\Persistence\Doctrine\DoctrineCampaignRepository;
use App\Play\Infrastructure\Persistence\Doctrine\DoctrineJournalEntryRepository;
use App\Randomness\Domain\RandomNumberGenerator;
use App\Shared\Application\Bus\Command;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Application\Bus\QueryBus;
use App\Studio\Application\PublishGameSystemRelease;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A solo player plays a campaign end to end through the real buses, handlers and Doctrine
 * repositories. The entity manager is cleared after every message, as between two requests.
 */
#[CoversClass(DoctrineCampaignRepository::class)]
#[CoversClass(DoctrineJournalEntryRepository::class)]
final class PlayCampaignJournalThroughTheBusesTest extends KernelTestCase
{
    private const string RELEASE_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a81';
    private const string PLAYER = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5101';
    private const string CAMPAIGN = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5201';

    private CommandBus $commands;
    private QueryBus $queries;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $container = self::getContainer();
        // Roll 2d6+1 shows 3 and 4; the weather table shows 2 (Clear); the fate question rolls 30.
        $container->set(RandomNumberGenerator::class, new ScriptedRandomNumberGenerator(3, 4, 2, 30));
        $this->commands = $container->get(CommandBus::class);
        $this->queries = $container->get(QueryBus::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->dispatch(new PublishGameSystemRelease(self::RELEASE_ID, ReleaseViews::contractDocExampleContent(), false));
    }

    #[Test]
    public function aPlayerCreatesACampaignStartsASceneAndRecordsTheJournal(): void
    {
        $this->dispatch(new CreateCampaign(self::CAMPAIGN, self::PLAYER, '  The lost mine ', 'example-journal'));
        $this->dispatch(new StartSession(self::CAMPAIGN, self::PLAYER));
        $this->dispatch(new StartScene(self::CAMPAIGN, self::PLAYER, 'At the gate'));
        $this->dispatch(new RecordNote('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5301', self::CAMPAIGN, self::PLAYER, 'The gate is open.'));
        $this->dispatch(new RecordRoll('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5302', self::CAMPAIGN, self::PLAYER, '2d6+1'));
        $this->dispatch(new RecordOracleTableResult('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5303', self::CAMPAIGN, self::PLAYER, 'weather'));
        $this->dispatch(new RecordLikelihoodAnswer('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5304', self::CAMPAIGN, self::PLAYER, 'fate', 'even', 5, 'Is it guarded?'));

        $campaigns = $this->queries->ask(new ListMyCampaigns(self::PLAYER));
        self::assertCount(1, $campaigns);
        self::assertSame(['The lost mine', 'example-journal', 1], [$campaigns[0]->name, $campaigns[0]->gameSystemKey, $campaigns[0]->releaseVersion]);

        $campaign = $this->queries->ask(new GetCampaign(self::CAMPAIGN, self::PLAYER));
        self::assertSame(1, $campaign->currentSessionNumber);
        self::assertSame(1, $campaign->currentSceneNumber);

        $journal = $this->queries->ask(new GetJournal(self::CAMPAIGN, self::PLAYER));
        self::assertSame(
            [
                ['0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5301', 1, 1, 'note'],
                ['0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5302', 1, 1, 'roll'],
                ['0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5303', 1, 1, 'oracle-table'],
                ['0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f5304', 1, 1, 'likelihood'],
            ],
            array_map(static fn (JournalEntryView $entry): array => [$entry->id, $entry->sessionNumber, $entry->sceneNumber, $entry->kind], $journal),
        );
        self::assertSame(['kind' => 'note', 'text' => 'The gate is open.'], $journal[0]->content);
        self::assertSame(
            ['kind' => 'roll', 'expression' => '2d6+1', 'total' => 8, 'groups' => [
                ['notation' => '2d6', 'sides' => 6, 'dice' => [['value' => 3, 'kept' => true], ['value' => 4, 'kept' => true]], 'subtotal' => 7],
            ]],
            $journal[1]->content,
        );
        self::assertSame(
            ['kind' => 'oracle-table', 'oracleKey' => 'weather', 'oracleName' => 'Weather', 'steps' => [
                ['tableKey' => 'weather', 'tableName' => 'Weather', 'dice' => '1d6', 'total' => 2, 'text' => 'Clear', 'nestedTableKey' => null],
            ]],
            $journal[2]->content,
        );
        self::assertSame(
            [
                'kind' => 'likelihood',
                'oracleKey' => 'fate',
                'oracleName' => 'Fate question',
                'question' => 'Is it guarded?',
                'answer' => 'yes',
                'roll' => 30,
                'sides' => 100,
                'effectiveTarget' => 50,
                'likelihood' => 'even',
                'likelihoodLabel' => '50/50',
                'chaosFactor' => 5,
            ],
            $journal[3]->content,
        );
    }

    private function dispatch(Command $command): void
    {
        $this->commands->dispatch($command);
        $this->entityManager->clear();
    }
}
