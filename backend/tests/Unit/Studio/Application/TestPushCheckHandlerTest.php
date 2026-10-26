<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\CheckTestPoolDieView;
use App\Studio\Application\CheckTestPoolView;
use App\Studio\Application\CheckTestRollView;
use App\Studio\Application\OwnedGameSystems;
use App\Studio\Application\TestPushCheck;
use App\Studio\Application\TestPushCheckHandler;
use App\Studio\Domain\Check\CheckTestRollNotPushable;
use App\Studio\Domain\Check\InvalidTestRoll;
use App\Studio\Domain\GameSystem\CheckNotFound;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use App\Tests\Support\Studio\InMemoryGameSystemRepository;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Sandbox pushes on a draft copying valid/v2-checks plus the pool check "grit": {hands} dice of 6
 * sides that succeed on 5+ (hands 0 to 10, default 4), bands miss up to 0, weak up to 1 and strong
 * above, and a push "Take a Condition".
 */
#[CoversClass(TestPushCheckHandler::class)]
#[CoversClass(CheckTestRollView::class)]
#[CoversClass(CheckTestPoolView::class)]
#[CoversClass(CheckTestPoolDieView::class)]
final class TestPushCheckHandlerTest extends TestCase
{
    private OwnedGameSystems $owned;

    protected function setUp(): void
    {
        $gameSystems = new InMemoryGameSystemRepository();
        $this->owned = new OwnedGameSystems($gameSystems);
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture('valid/v2-checks'))->toArray();
        $draft = GameSystem::createFromRelease(GameSystemId::fromString('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f0301'), 'mine', 'Draft', null, 'ann', $source, new \DateTimeImmutable('2026-10-17T10:00:00+00:00'));
        $draft->saveCheck(null, ['key' => 'grit', 'label' => 'Grit', 'pool' => ['count' => ['formula' => '{hands}'], 'sides' => 6, 'successAt' => 5], 'push' => ['label' => 'Take a Condition'], 'bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => 0], ['key' => 'weak', 'label' => 'Weak', 'upTo' => 1], ['key' => 'strong', 'label' => 'Strong']]], new \DateTimeImmutable());
        $gameSystems->add($draft);
    }

    #[Test]
    public function thePushedPoolIsTheViewWithTheReRolledDicesAndTheSuccessesBefore(): void
    {
        $view = $this->push([6], new TestPushCheck('ann', 'mine', 'grit', ['hands' => 3], [], null, null, [1, 5, 6]));

        self::assertNull($view->dice);
        self::assertEquals(new CheckTestPoolView(3, 6, 5, 3, '{hands}', [new CheckTestPoolDieView(6, true, true), new CheckTestPoolDieView(5, true, false), new CheckTestPoolDieView(6, true, false)], 3), $view->pool);
        self::assertSame([3, 'strong', 'Take a Condition', 2], [$view->total, $view->band, $view->pushLabel, $view->rolledSuccesses]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function facesThatAreNotAListOfIntegers(): iterable
    {
        yield 'a string' => ['1,2,3'];
        yield 'null' => [null];
        yield 'an object' => [['a' => 1, 'b' => 2, 'c' => 3]];
        yield 'a face that is a string' => [[1, '2', 3]];
        yield 'a face that is a float' => [[1, 2.0, 3]];
    }

    #[Test]
    #[DataProvider('facesThatAreNotAListOfIntegers')]
    public function facesThatAreNotAListOfIntegersAreRefusedBeforeRolling(mixed $faces): void
    {
        $this->expectException(InvalidTestRoll::class);
        $this->expectExceptionMessageIsOrContains('faces: must be a list of integers');

        $this->push([], new TestPushCheck('ann', 'mine', 'grit', ['hands' => 3], [], null, null, $faces));
    }

    #[Test]
    public function aDiceCheckCannotBePushed(): void
    {
        $this->expectException(CheckTestRollNotPushable::class);

        $this->push([], new TestPushCheck('ann', 'mine', 'skill', [], [], null, null, [7]));
    }

    #[Test]
    public function aValueTheSheetDoesNotAcceptIsRefusedOnItsPath(): void
    {
        $this->expectException(InvalidTestRoll::class);
        $this->expectExceptionMessageIsOrContains('sheet.hands: must be 0 to 10, got 11');

        $this->push([], new TestPushCheck('ann', 'mine', 'grit', ['hands' => 11], [], null, null, [1]));
    }

    #[Test]
    public function aDifficultyThatIsAStringIsRefused(): void
    {
        $this->expectException(InvalidTestRoll::class);
        $this->expectExceptionMessageIsOrContains('difficulty: must be an integer');

        $this->push([], new TestPushCheck('ann', 'mine', 'grit', [], [], '13', null, [1, 2, 3, 4]));
    }

    #[Test]
    public function anotherUsersDraftIsNotFoundAndAnUnknownCheckIsNotFound(): void
    {
        try {
            $this->push([], new TestPushCheck('bob', 'mine', 'grit', [], [], null, null, [1]));
            self::fail('The draft is not found.');
        } catch (GameSystemNotFound) {
            self::addToAssertionCount(1);
        }

        $this->expectException(CheckNotFound::class);

        $this->push([], new TestPushCheck('ann', 'mine', 'ghost', [], [], null, null, [1]));
    }

    /**
     * @param list<int> $dice
     */
    private function push(array $dice, TestPushCheck $query): CheckTestRollView
    {
        return (new TestPushCheckHandler($this->owned, new ScriptedRandomNumberGenerator(...$dice)))($query);
    }
}
