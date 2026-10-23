<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Application;

use App\Play\Application\CampaignJournal;
use App\Play\Application\CampaignNotFound;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(CampaignJournal::class)]
final class CampaignJournalTest extends JournalTestCase
{
    #[Test]
    public function theOwnerGetsTheCampaignToRecordIn(): void
    {
        self::assertSame('campaign-1', $this->journal->campaign('campaign-1', 'user-1')->id()->toString());
    }

    /**
     * Every Record* handler reaches the ownership rule through this call, so they do not repeat it.
     */
    #[Test]
    public function anotherPlayersCampaignIsNotFound(): void
    {
        $this->expectException(CampaignNotFound::class);

        $this->journal->campaign('campaign-1', 'user-2');
    }
}
