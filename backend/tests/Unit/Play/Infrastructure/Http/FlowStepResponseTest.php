<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\Http;

use App\Play\Domain\Campaign\FlowRun\FlowStepView;
use App\Play\Domain\Campaign\FlowRun\StepKind;
use App\Play\Domain\GameSystem\Flow\ChoiceOption;
use App\Play\Domain\GameSystem\Flow\ChoiceStep;
use App\Play\Domain\GameSystem\Flow\ConditionStep;
use App\Play\Domain\GameSystem\Flow\Outcome;
use App\Play\Infrastructure\Http\FlowStepResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

        FlowStepResponse::fromView(new FlowStepView(StepKind::Condition, $step, $step->title, $step->prompt, $step->tip));
    }

    /**
     * @return iterable<string, array{?string, list<string>}>
     */
    public static function skips(): iterable
    {
        yield 'the skip option is left out' => ['none', ['a', 'b']];
        yield 'a skip naming no option leaves them all' => ['gone', ['a', 'b', 'none']];
        yield 'no skip leaves them all' => [null, ['a', 'b', 'none']];
    }

    /**
     * @param list<string> $offered
     */
    #[Test]
    #[DataProvider('skips')]
    public function aChoiceOffersNoOptionThatItsSkipButtonAlreadyDoes(?string $skip, array $offered): void
    {
        $step = new ChoiceStep('pick', 'Which?', null, null, false, null, [], [
            new ChoiceOption('a', 'A', new Outcome()),
            new ChoiceOption('b', 'B', new Outcome()),
            new ChoiceOption('none', 'None', new Outcome()),
        ], $skip);

        $response = FlowStepResponse::fromView(new FlowStepView(StepKind::Choice, $step, $step->title, $step->prompt, $step->tip));

        self::assertSame($offered, array_map(static fn (\App\Play\Infrastructure\Http\FlowStepOptionResponse $option): string => $option->key, $response->options));
    }
}
