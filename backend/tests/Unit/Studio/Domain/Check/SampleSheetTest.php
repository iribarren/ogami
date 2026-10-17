<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Check;

use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\Reference;
use App\Studio\Domain\Check\CheckTestRollFailed;
use App\Studio\Domain\Check\InvalidTestRoll;
use App\Studio\Domain\Check\SampleSheet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The sample values a test roll reads (ADR 0032): only number and track fields and trackers take a
 * sample, everything else a value is read the way Play's SheetValues reads a Character's.
 */
#[CoversClass(SampleSheet::class)]
#[CoversClass(InvalidTestRoll::class)]
final class SampleSheetTest extends TestCase
{
    #[Test]
    public function aMissingSampleTakesTheDefaultsAndTheTrackersInitialValue(): void
    {
        $sheet = SampleSheet::fromDocument($this->document(), [], []);

        self::assertSame(
            [4, 0, 2, 0, 8, 2, 0, 3, 5],
            [
                $this->read($sheet, Reference::field('hands')),
                $this->read($sheet, Reference::field('zero')),
                $this->read($sheet, Reference::field('floor')),
                $this->read($sheet, Reference::field('hp')),
                $this->read($sheet, Reference::trackMax('hp')),
                $this->read($sheet, Reference::field('edge')),
                $this->read($sheet, Reference::tracker('alarm')),
                $this->read($sheet, Reference::tracker('chaos')),
                $this->read($sheet, Reference::field('capped')),
            ],
            'A number reads its default, else 0 moved inside min..max; a track 0 of its max; a counter its initial value, a clock 0.',
        );
    }

    #[Test]
    public function samplesReplaceTheDefaultsAndDerivedValuesFollowThem(): void
    {
        $sheet = SampleSheet::fromDocument($this->document(), ['hands' => 9, 'hp' => 5], ['alarm' => 6, 'chaos' => -2]);

        self::assertSame(
            [9, 5, 8, 4, 6, -2],
            [
                $this->read($sheet, Reference::field('hands')),
                $this->read($sheet, Reference::field('hp')),
                $this->read($sheet, Reference::trackMax('hp')),
                $this->read($sheet, Reference::field('edge')),
                $this->read($sheet, Reference::tracker('alarm')),
                $this->read($sheet, Reference::tracker('chaos')),
            ],
        );
    }

    #[Test]
    public function aTrackWithoutAFixedMaxTakesItsSampleAsMax(): void
    {
        $sheet = SampleSheet::fromDocument($this->document(), ['open' => 7], []);

        self::assertSame([7, 7], [$this->read($sheet, Reference::field('open')), $this->read($sheet, Reference::trackMax('open'))]);
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function aDerivedValueThatFailsOrReadsOneThatFailedHasNoValue(): void
    {
        $sheet = SampleSheet::fromDocument($this->document(), [], []);

        foreach (['broken', 'reader', 'loop-a', 'loop-b'] as $key) {
            try {
                $sheet->value(Reference::field($key));
                self::fail('"'.$key.'" has no value.');
            } catch (FormulaEvaluationFailed) {
            }
        }
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, array<array-key, mixed>, string}> sheet samples, tracker samples, the message
     */
    public static function badSamples(): iterable
    {
        yield 'a key the sheet lacks' => [['ghost' => 1], [], 'sheet.ghost: is not a field of the sheet'];
        yield 'a derived field' => [['edge' => 1], [], 'sheet.edge: is derived, so it takes no sample'];
        yield 'a text field' => [['name' => 1], [], 'sheet.name: is not a number or track field'];
        yield 'a string' => [['hands' => '5'], [], 'sheet.hands: must be an integer'];
        yield 'a float' => [['hands' => 5.0], [], 'sheet.hands: must be an integer'];
        yield 'a boolean' => [['hands' => true], [], 'sheet.hands: must be an integer'];
        yield 'null' => [['hands' => null], [], 'sheet.hands: must be an integer'];
        yield 'a number below its min' => [['hands' => -1], [], 'sheet.hands: must be 0 to 10, got -1'];
        yield 'a number above its max' => [['hands' => 11], [], 'sheet.hands: must be 0 to 10, got 11'];
        yield 'a number outside the contract limits' => [['zero' => 1_000_001], [], 'sheet.zero: must be -1000000 to 1000000, got 1000001'];
        yield 'a track below 0' => [['hp' => -1], [], 'sheet.hp: must be 0 to 8, got -1'];
        yield 'a track above its max' => [['hp' => 9], [], 'sheet.hp: must be 0 to 8, got 9'];
        yield 'a track without a max above 999' => [['open' => 1000], [], 'sheet.open: must be 0 to 999, got 1000'];
        yield 'a tracker the draft lacks' => [[], ['ghost' => 1], 'trackers.ghost: is not a tracker of the draft'];
        yield 'a tracker that is not an integer' => [[], ['alarm' => 1.5], 'trackers.alarm: must be an integer'];
        yield 'a clock above its segments' => [[], ['alarm' => 7], 'trackers.alarm: must be 0 to 6, got 7'];
        yield 'a counter below its min' => [[], ['chaos' => -6], 'trackers.chaos: must be -5 to 5, got -6'];
        yield 'a sheet that is a list' => [[5], [], 'sheet.0: is not a field of the sheet'];
        yield 'trackers that are a list' => [[], [5], 'trackers.0: is not a tracker of the draft'];
    }

    /**
     * @param array<array-key, mixed> $samples
     * @param array<array-key, mixed> $trackers
     */
    #[Test]
    #[DataProvider('badSamples')]
    public function aSampleThatIsNotAValueOfItsFieldIsRefusedOnItsPath(array $samples, array $trackers, string $message): void
    {
        $this->expectException(InvalidTestRoll::class);
        $this->expectExceptionMessageIsOrContains($message);

        SampleSheet::fromDocument($this->document(), $samples, $trackers);
    }

    #[Test]
    public function samplesThatAreNotObjectsAreRefusedOnTheirPath(): void
    {
        foreach ([['sheet', 'x'], ['trackers', 5], ['sheet', true]] as [$path, $value]) {
            try {
                'sheet' === $path ? SampleSheet::fromDocument($this->document(), $value, []) : SampleSheet::fromDocument($this->document(), [], $value);
                self::fail($path.' is not an object.');
            } catch (InvalidTestRoll $invalid) {
                self::assertStringStartsWith($path.': must be an object', $invalid->getMessage());
            }
        }
    }

    #[Test]
    public function nullSamplesAreAbsentSoTheDefaultsApply(): void
    {
        $sheet = SampleSheet::fromDocument($this->document(), null, null);

        self::assertSame([4, 3], [$this->read($sheet, Reference::field('hands')), $this->read($sheet, Reference::tracker('chaos'))]);
    }

    #[Test]
    public function aFailedDerivedValueIsReportedAgainAndNeverAsACycle(): void
    {
        $sheet = SampleSheet::fromDocument($this->document(), [], []);

        foreach (['broken', 'reader', 'broken', 'reader'] as $key) {
            try {
                $sheet->value(Reference::field($key));
                self::fail('"'.$key.'" has no value.');
            } catch (FormulaEvaluationFailed $failed) {
                self::assertSame(\sprintf('The formula reads "%s", which has no value.', $key), $failed->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreadableDocuments(): iterable
    {
        yield 'a sheet that is not an object' => [['sheet' => 'none']];
        yield 'fields that are not a list' => [['sheet' => ['fields' => 'none']]];
        yield 'a field without a key' => [['sheet' => ['fields' => [['type' => 'number']]]]];
        yield 'a number field with a non-integer default' => [['sheet' => ['fields' => [['key' => 'a', 'type' => 'number', 'default' => 'x']]]]];
        yield 'a derived field without a readable formula' => [['sheet' => ['fields' => [['key' => 'a', 'type' => 'derived', 'formula' => '{']]]]];
        yield 'a track with a non-integer max' => [['sheet' => ['fields' => [['key' => 'a', 'type' => 'track', 'max' => 'x']]]]];
        yield 'trackers that are not a list' => [['trackers' => 'none']];
        yield 'a tracker without a key' => [['trackers' => [['kind' => 'clock', 'segments' => 3]]]];
        yield 'a counter without bounds' => [['trackers' => [['key' => 'a', 'kind' => 'counter']]]];
        yield 'a tracker of an unknown kind' => [['trackers' => [['key' => 'a', 'kind' => 'gauge']]]];
    }

    /**
     * @param array<string, mixed> $document
     */
    #[Test]
    #[DataProvider('unreadableDocuments')]
    public function aStoredSheetOrTrackerThatIsNotReadableFailsClosedWithAClearError(array $document): void
    {
        $this->expectException(CheckTestRollFailed::class);
        $this->expectExceptionMessageMatches('/^The saved draft cannot be test-rolled: .+\.$/');

        SampleSheet::fromDocument($document, [], []);
    }

    #[Test]
    public function aDraftWithoutASheetOrTrackersHasNothingToSample(): void
    {
        $sheet = SampleSheet::fromDocument([], [], []);

        $this->expectException(FormulaEvaluationFailed::class);

        $sheet->value(Reference::field('hands'));
    }

    #[Test]
    public function aCheckReferenceNeverReadsTheSheet(): void
    {
        $this->expectException(FormulaEvaluationFailed::class);

        SampleSheet::fromDocument($this->document(), [], [])->value(Reference::check('difficulty'));
    }

    private function read(SampleSheet $sheet, Reference $reference): int
    {
        return $sheet->value($reference);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        return [
            'sheet' => ['fields' => [
                ['key' => 'hands', 'label' => 'Hands', 'type' => 'number', 'min' => 0, 'max' => 10, 'default' => 4],
                ['key' => 'zero', 'label' => 'Zero', 'type' => 'number'],
                ['key' => 'floor', 'label' => 'Floor', 'type' => 'number', 'min' => 2],
                ['key' => 'capped', 'label' => 'Capped', 'type' => 'number', 'max' => 5, 'min' => 5],
                ['key' => 'hp', 'label' => 'HP', 'type' => 'track', 'max' => 8],
                ['key' => 'open', 'label' => 'Open', 'type' => 'track'],
                ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                ['key' => 'edge', 'label' => 'Edge', 'type' => 'derived', 'formula' => '{hands} / 2'],
                ['key' => 'broken', 'label' => 'Broken', 'type' => 'derived', 'formula' => '{hands} / {zero}'],
                ['key' => 'reader', 'label' => 'Reader', 'type' => 'derived', 'formula' => '{broken} + 1'],
                ['key' => 'loop-a', 'label' => 'A', 'type' => 'derived', 'formula' => '{loop-b}'],
                ['key' => 'loop-b', 'label' => 'B', 'type' => 'derived', 'formula' => '{loop-a}'],
            ]],
            'trackers' => [
                ['key' => 'alarm', 'name' => 'Alarm', 'kind' => 'clock', 'segments' => 6],
                ['key' => 'chaos', 'name' => 'Chaos', 'kind' => 'counter', 'min' => -5, 'max' => 5, 'initial' => 3],
            ],
        ];
    }
}
