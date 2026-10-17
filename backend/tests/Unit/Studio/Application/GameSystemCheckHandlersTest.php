<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\CheckFieldView;
use App\Studio\Application\CheckTrackerView;
use App\Studio\Application\DeleteCheck;
use App\Studio\Application\DeleteCheckHandler;
use App\Studio\Application\GameSystemChecksView;
use App\Studio\Application\GetGameSystemChecks;
use App\Studio\Application\GetGameSystemChecksHandler;
use App\Studio\Application\MoveCheck;
use App\Studio\Application\MoveCheckHandler;
use App\Studio\Application\OwnedGameSystems;
use App\Studio\Application\SaveCheck;
use App\Studio\Application\SaveCheckHandler;
use App\Studio\Domain\GameSystem\CheckInUse;
use App\Studio\Domain\GameSystem\CheckKeyTaken;
use App\Studio\Domain\GameSystem\CheckNotFound;
use App\Studio\Domain\GameSystem\GameSystem;
use App\Studio\Domain\GameSystem\GameSystemId;
use App\Studio\Domain\GameSystem\GameSystemModifiedConcurrently;
use App\Studio\Domain\GameSystem\GameSystemNotFound;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Tests\Support\Studio\FixedClock;
use App\Tests\Support\Studio\InMemoryGameSystemRepository;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Drafts copy valid/v2-checks: the checks skill (named by a check step) and luck, the fields hands
 * and edge (derived) and an alarm clock of 6 segments.
 */
#[CoversClass(SaveCheckHandler::class)]
#[CoversClass(DeleteCheckHandler::class)]
#[CoversClass(MoveCheckHandler::class)]
#[CoversClass(GetGameSystemChecksHandler::class)]
final class GameSystemCheckHandlersTest extends TestCase
{
    private InMemoryGameSystemRepository $gameSystems;
    private OwnedGameSystems $owned;
    private FixedClock $clock;
    private int $drafts = 0;

    protected function setUp(): void
    {
        $this->gameSystems = new InMemoryGameSystemRepository();
        $this->owned = new OwnedGameSystems($this->gameSystems);
        $this->clock = new FixedClock('2026-10-17T10:00:00+00:00');
    }

    #[Test]
    public function theReadModelListsChecksTheReferenceCatalogAndUsages(): void
    {
        $this->draft('my-checks');

        $view = $this->checks('ann', 'my-checks');

        self::assertSame([1, 2], [$view->version, $view->schemaVersion]);
        self::assertSame(['skill', 'luck'], array_column($view->checks, 'key'));
        self::assertSame('1d10', $view->checks[0]['dice']);
        self::assertEquals([new CheckFieldView('hands', 'Hands', 'number', null), new CheckFieldView('edge', 'Edge', 'derived', null)], $view->fields);
        self::assertEquals([new CheckTrackerView('alarm', 'Alarm', 0)], $view->trackers);
        self::assertSame(['skill' => ['sceneTypes[0].play[0].check'], 'luck' => []], $view->usages);
    }

    #[Test]
    public function theCatalogListsNumberTrackAndDerivedFieldsWithTheTrackMaxAndCounterInitialValues(): void
    {
        $draft = $this->draft('my-checks', 'valid/v2-sheet-effects');
        $document = ReleaseArrays::with($draft->document(), 'trackers', [
            ['key' => 'heat', 'name' => 'Heat', 'kind' => 'counter', 'min' => 0, 'max' => 10, 'initial' => 3],
            ['key' => 'dread', 'name' => 'Dread', 'kind' => 'clock', 'segments' => 6],
        ]);
        $document = ReleaseArrays::with($document, 'sheet', ['fields' => [
            ['key' => 'pow', 'label' => 'Power', 'type' => 'number'],
            ['key' => 'hp', 'label' => 'Hit points', 'type' => 'track', 'max' => 12],
            ['key' => 'luck', 'label' => 'Luck', 'type' => 'track'],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'text'],
            ['key' => 'gear', 'label' => 'Gear', 'type' => 'list'],
        ]]);
        $draft->restore($document, $this->clock->now());

        $view = $this->checks('ann', 'my-checks');

        self::assertEquals([new CheckFieldView('pow', 'Power', 'number', null), new CheckFieldView('hp', 'Hit points', 'track', 12), new CheckFieldView('luck', 'Luck', 'track', null)], $view->fields);
        self::assertEquals([new CheckTrackerView('heat', 'Heat', 3), new CheckTrackerView('dread', 'Dread', 0)], $view->trackers);
    }

    #[Test]
    public function aSchemaVersion1DraftReadsAsEmpty(): void
    {
        $this->draft('my-journal', 'valid/contract-doc-example');

        $view = $this->checks('ann', 'my-journal');

        self::assertSame([1, [], [], [], []], [$view->schemaVersion, $view->checks, $view->fields, $view->trackers, $view->usages]);
    }

    #[Test]
    public function aDraftWithoutChecksReadsAsEmpty(): void
    {
        $draft = $this->draft('my-checks', 'valid/v2-minimal');
        $document = $draft->document();
        unset($document['checks']);
        $draft->restore($document, $this->clock->now());

        $view = $this->checks('ann', 'my-checks');

        self::assertSame([[], []], [$view->checks, $view->usages]);
    }

    #[Test]
    public function aMalformedDraftIsReadDefensively(): void
    {
        $draft = $this->draft('broken');
        $document = ReleaseArrays::with($draft->document(), 'checks', ['text', ['label' => 'No key'], ['key' => 7], ['key' => 'ok', 'dice' => '1d6'], ['key' => 'stray']]);
        $document = ReleaseArrays::with($document, 'sheet', ['fields' => ['text', ['label' => 'No key', 'type' => 'number'], ['key' => 'a', 'type' => 'number', 'label' => 4], ['key' => 'b', 'type' => 'track', 'max' => 'x'], ['key' => 'c']]]);
        $document = ReleaseArrays::with($document, 'trackers', ['t', ['name' => 'No key'], ['key' => 'a'], ['key' => 'b', 'name' => 4, 'initial' => 'x'], ['key' => 'c', 'name' => 'C', 'initial' => 2]]);
        $document = ReleaseArrays::with($document, 'sceneTypes', [['play' => [['kind' => 'check', 'check' => 'ok'], ['kind' => 'check', 'check' => 3]]]]);
        $draft->restore($document, $this->clock->now());

        $view = $this->checks('ann', 'broken');

        self::assertSame([['key' => 'ok', 'dice' => '1d6'], ['key' => 'stray']], $view->checks);
        self::assertEquals([new CheckFieldView('a', '', 'number', null), new CheckFieldView('b', '', 'track', null)], $view->fields);
        self::assertEquals([new CheckTrackerView('a', '', null), new CheckTrackerView('b', '', null), new CheckTrackerView('c', 'C', 2)], $view->trackers);
        self::assertSame(['ok' => ['sceneTypes[0].play[0].check'], 'stray' => []], $view->usages);
    }

    #[Test]
    public function checksThatAreNotAListOrASheetThatIsNotAnObjectReadAsEmpty(): void
    {
        $draft = $this->draft('broken');
        foreach ([['checks' => 'nope', 'sheet' => 'nope', 'trackers' => 'nope'], ['checks' => ['a' => []], 'sheet' => ['fields' => 'x'], 'trackers' => ['a' => 1]]] as $parts) {
            $document = $draft->document();
            foreach ($parts as $section => $value) {
                $document = ReleaseArrays::with($document, $section, $value);
            }

            $draft->restore($document, $this->clock->now());

            $view = $this->checks('ann', 'broken');

            self::assertSame([[], [], [], []], [$view->checks, $view->fields, $view->trackers, $view->usages]);
        }
    }

    #[Test]
    public function aCheckIsSavedRenamedMovedAndDeleted(): void
    {
        $this->draft('my-checks');

        $this->save(null, self::newCheck());
        $this->save('grit', [...self::newCheck(), 'key' => 'nerve'], version: 2);
        $this->move('nerve', 0, version: 3);

        $view = $this->checks('ann', 'my-checks');
        self::assertSame(['nerve', 'skill', 'luck'], array_column($view->checks, 'key'));
        self::assertSame(4, $view->version);

        $this->delete('nerve', version: 4);

        $view = $this->checks('ann', 'my-checks');
        self::assertSame(['skill', 'luck'], array_column($view->checks, 'key'));
        self::assertSame(5, $view->version);
        self::assertEquals($this->clock->now(), $this->gameSystems->ofKey('my-checks')?->updatedAt());
    }

    /**
     * @return iterable<string, array{\Closure(self, string, int): void}>
     */
    public static function commands(): iterable
    {
        yield 'save' => [static fn (self $t, string $user, int $version) => $t->save(null, self::newCheck(), $user, $version)];
        yield 'delete' => [static fn (self $t, string $user, int $version) => $t->delete('luck', $user, $version)];
        yield 'move' => [static fn (self $t, string $user, int $version) => $t->move('luck', 0, $user, $version)];
    }

    /**
     * @param \Closure(self, string, int): void $command
     */
    #[Test]
    #[DataProvider('commands')]
    public function aStaleExpectedVersionIsRefusedAndChangesNothing(\Closure $command): void
    {
        $draft = $this->draft('my-checks');

        $this->expectRefusal(GameSystemModifiedConcurrently::class, $draft, fn () => $command($this, 'ann', 2));
    }

    /**
     * @param \Closure(self, string, int): void $command
     */
    #[Test]
    #[DataProvider('commands')]
    public function anotherOwnersDraftIsNotFound(\Closure $command): void
    {
        $draft = $this->draft('my-checks');

        $this->expectRefusal(GameSystemNotFound::class, $draft, fn () => $command($this, 'bob', 1));
    }

    #[Test]
    public function theReadModelOfAnotherOwnersOrAMissingDraftIsNotFound(): void
    {
        $this->draft('my-checks');

        $refused = [];
        foreach ([['bob', 'my-checks'], ['ann', 'nothing']] as [$user, $key]) {
            try {
                $this->checks($user, $key);
            } catch (GameSystemNotFound) {
                $refused[] = $user;
            }
        }

        self::assertSame(['bob', 'ann'], $refused);
    }

    /**
     * @return iterable<string, array{\Closure(self): void, class-string<\Throwable>}>
     */
    public static function refusedCommands(): iterable
    {
        yield 'an invalid check' => [static fn (self $t) => $t->save(null, [...self::newCheck(), 'dice' => '2d']), InvalidReleaseContent::class];
        yield 'a taken key' => [static fn (self $t) => $t->save(null, self::newCheck('luck')), CheckKeyTaken::class];
        yield 'a missing original key' => [static fn (self $t) => $t->save('nowhere', self::newCheck()), CheckNotFound::class];
        yield 'renaming a referenced check' => [static fn (self $t) => $t->save('skill', [...self::newCheck(), 'key' => 'aptitude']), CheckInUse::class];
        yield 'dropping a referenced band' => [static fn (self $t) => $t->save('skill', [...self::newCheck('skill'), 'bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => 7], ['key' => 'hit', 'label' => 'Hit']]]), CheckInUse::class];
        yield 'deleting a missing check' => [static fn (self $t) => $t->delete('nowhere'), CheckNotFound::class];
        yield 'deleting a referenced check' => [static fn (self $t) => $t->delete('skill'), CheckInUse::class];
        yield 'moving a missing check' => [static fn (self $t) => $t->move('nowhere', 0), CheckNotFound::class];
        yield 'moving out of range' => [static fn (self $t) => $t->move('skill', 2), InvalidReleaseContent::class];
    }

    /**
     * @param \Closure(self): void     $command
     * @param class-string<\Throwable> $refusal
     */
    #[Test]
    #[DataProvider('refusedCommands')]
    public function aRefusedCommandLeavesTheStoredDraftUntouched(\Closure $command, string $refusal): void
    {
        $draft = $this->draft('my-checks');
        $updatedAt = $draft->updatedAt();

        $this->expectRefusal($refusal, $draft, fn () => $command($this));

        self::assertEquals($updatedAt, $draft->updatedAt());
    }

    #[Test]
    public function aSchemaVersion1DraftRefusesEveryCheckCommand(): void
    {
        $draft = $this->draft('my-journal', 'valid/contract-doc-example');

        $this->expectRefusal(InvalidReleaseContent::class, $draft, fn () => $this->save(null, self::newCheck(), key: 'my-journal'));
        $this->expectRefusal(InvalidReleaseContent::class, $draft, fn () => $this->delete('grit', key: 'my-journal'));
        $this->expectRefusal(InvalidReleaseContent::class, $draft, fn () => $this->move('grit', 0, key: 'my-journal'));
    }

    /**
     * @param class-string<\Throwable> $refusal
     */
    private function expectRefusal(string $refusal, GameSystem $draft, \Closure $command): void
    {
        $before = $draft->document();
        $version = $draft->version();

        try {
            $command();
            self::fail('Expected '.$refusal);
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($refusal, $thrown);
        }

        self::assertSame($before, $draft->document());
        self::assertSame($version, $draft->version());
    }

    /**
     * @return array<string, mixed>
     */
    private static function newCheck(string $key = 'grit'): array
    {
        return ['key' => $key, 'label' => 'Grit', 'dice' => '2d6', 'bands' => [['key' => 'miss', 'label' => 'Miss', 'upTo' => 7], ['key' => 'hit', 'label' => 'Hit']]];
    }

    /**
     * @param array<mixed> $check
     */
    public function save(?string $originalKey, array $check, string $user = 'ann', int $version = 1, string $key = 'my-checks'): void
    {
        (new SaveCheckHandler($this->owned, $this->gameSystems, $this->clock))(new SaveCheck($user, $key, $version, $originalKey, $check));
    }

    public function delete(string $check, string $user = 'ann', int $version = 1, string $key = 'my-checks'): void
    {
        (new DeleteCheckHandler($this->owned, $this->gameSystems, $this->clock))(new DeleteCheck($user, $key, $version, $check));
    }

    public function move(string $check, int $index, string $user = 'ann', int $version = 1, string $key = 'my-checks'): void
    {
        (new MoveCheckHandler($this->owned, $this->gameSystems, $this->clock))(new MoveCheck($user, $key, $version, $check, $index));
    }

    private function checks(string $user, string $key): GameSystemChecksView
    {
        return (new GetGameSystemChecksHandler($this->owned))(new GetGameSystemChecks($user, $key));
    }

    private function draft(string $key, string $fixture = 'valid/v2-checks'): GameSystem
    {
        $source = ReleaseContent::fromArray(ReleaseArrays::fixture($fixture))->toArray();
        $draft = GameSystem::createFromRelease(GameSystemId::fromString(\sprintf('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f%04x', 0x200 + ++$this->drafts)), $key, 'Draft', null, 'ann', $source, $this->clock->now());
        $this->gameSystems->add($draft);

        return $draft;
    }
}
