<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign;

use App\Play\Domain\Campaign\CampaignRepository;
use App\Tests\Support\Play\CampaignRepositoryContract;
use App\Tests\Support\Play\InMemoryCampaignRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the CampaignRepository contract on the in-memory double that Application tests rely on.
 */
#[CoversClass(InMemoryCampaignRepository::class)]
final class InMemoryCampaignRepositoryTest extends TestCase
{
    use CampaignRepositoryContract;

    private InMemoryCampaignRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryCampaignRepository();
    }

    protected function campaigns(): CampaignRepository
    {
        return $this->repository;
    }

    protected function forgetLoaded(): void
    {
        // Nothing to forget: the double hands out copies.
    }
}
