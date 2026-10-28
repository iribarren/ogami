<?php

declare(strict_types=1);

namespace App\Play\Infrastructure\Http;

use App\Play\Application\ReleaseUpgradePreview;
use App\Play\Domain\Campaign\Upgrade\ReleaseUpgradePlan;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The preview of a release upgrade: nothing to upgrade, or the plan to confirm.
 */
#[OA\Schema(required: ['upToDate', 'currentVersion', 'plan'])]
final readonly class ReleaseUpgradeResponse
{
    private function __construct(
        #[OA\Property(description: 'Whether the campaign already plays the latest release of its GameSystem: then there is no plan.')]
        public bool $upToDate,
        #[OA\Property(description: 'The release version the campaign plays.', example: 2)]
        public int $currentVersion,
        #[OA\Property(ref: new Model(type: ReleaseUpgradePlanResponse::class), description: 'What upgrading to the latest release changes; null when up to date.', nullable: true)]
        public ?ReleaseUpgradePlanResponse $plan,
    ) {
    }

    public static function fromPreview(ReleaseUpgradePreview $preview): self
    {
        return new self($preview->upToDate(), $preview->currentVersion, $preview->plan instanceof ReleaseUpgradePlan ? ReleaseUpgradePlanResponse::fromPlan($preview->plan, $preview->targetGear) : null);
    }
}
