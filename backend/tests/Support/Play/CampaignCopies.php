<?php

declare(strict_types=1);

namespace App\Tests\Support\Play;

use App\Play\Domain\Campaign\Campaign;

/**
 * Copies of a Campaign for comparisons in tests.
 */
final class CampaignCopies
{
    /**
     * A copy of the campaign without its FlowRun, to compare a guided campaign with one rebuilt from
     * storage, which holds none until the FlowRun is stored (play-flow-run slice 15).
     */
    public static function withoutFlowRun(Campaign $campaign): Campaign
    {
        $copy = clone $campaign;
        new \ReflectionProperty(Campaign::class, 'flowRun')->setValue($copy, null);

        return $copy;
    }
}
