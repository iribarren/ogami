<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\FlowRun\FlowStepView;
use App\Play\Domain\Campaign\FlowRun\StepKind;
use App\Play\Domain\GameSystem\Flow\ConditionStep;
use App\Play\Infrastructure\Http\FlowStepResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlowStepResponse::class)]
final class FlowStepResponseTest extends TestCase
{
    #[Test]
    public function aConditionStepNeverWaitsSoItHasNoResponse(): void
    {
        $step = new ConditionStep('cool', 'How hot is it?', null, null, false, null, [], 'heat', []);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Condition step "cool" never waits: it cannot be the current step.');

        FlowStepResponse::fromView(new FlowStepView(StepKind::Condition, $step));
    }
}
