<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Journal;

use App\Play\Domain\Journal\JournalEntryRepository;
use App\Tests\Support\Play\InMemoryJournalEntryRepository;
use App\Tests\Support\Play\JournalEntryRepositoryContract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the JournalEntryRepository contract on the in-memory double that Application tests rely on.
 */
#[CoversClass(InMemoryJournalEntryRepository::class)]
final class InMemoryJournalEntryRepositoryTest extends TestCase
{
    use JournalEntryRepositoryContract;

    private InMemoryJournalEntryRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryJournalEntryRepository();
    }

    protected function entries(): JournalEntryRepository
    {
        return $this->repository;
    }

    protected function givenCampaign(string $campaignId): void
    {
        // The double keeps no campaigns: any campaign id is accepted.
    }

    protected function forgetLoaded(): void
    {
        // Nothing to forget: entries are immutable.
    }
}
