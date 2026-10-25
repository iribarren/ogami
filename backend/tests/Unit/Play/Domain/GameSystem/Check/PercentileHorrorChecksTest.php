<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\GameSystem\Check;

use App\Play\Domain\Campaign\Characters\SheetValues;
use App\Play\Domain\GameSystem\Check\Check;
use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Randomness\ScriptedRandomNumberGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The six percentile bands of the shipped `percentile-horror` preset, through the Play check
 * domain: a d100 roll under the skill, with a critical on 1, extreme and hard successes at a fifth
 * and a half of the skill, and a fumble on 100, or on 96 and above when the skill is under 50.
 */
#[CoversClass(Check::class)]
final class PercentileHorrorChecksTest extends TestCase
{
    private GameSystemSnapshot $release;

    protected function setUp(): void
    {
        /** @var array<string, mixed> $content */
        $content = json_decode((string) file_get_contents(__DIR__.'/../../../../../../presets/percentile-horror.json'), true, flags: \JSON_THROW_ON_ERROR);
        $this->release = new GameSystemReleaseTranslator()->translate(ReleaseViews::of($content));
    }

    /**
     * @return iterable<string, array{int, int, string}> skill, roll and band
     */
    public static function edges(): iterable
    {
        yield 'a 1 is critical' => [50, 1, 'critical'];
        yield 'a 1 is critical even on a tiny skill' => [3, 1, 'critical'];
        yield 'a fifth of the skill is extreme' => [50, 10, 'extreme'];
        yield 'just over a fifth is hard' => [50, 11, 'hard'];
        yield 'half the skill is hard' => [50, 25, 'hard'];
        yield 'just over half is regular' => [50, 26, 'regular'];
        yield 'the skill itself is regular' => [50, 50, 'regular'];
        yield 'just over the skill fails' => [50, 51, 'failure'];
        yield '99 fails at skill 50' => [50, 99, 'failure'];
        yield '100 is a fumble at skill 50' => [50, 100, 'fumble'];
        yield '95 fails at skill 49' => [49, 95, 'failure'];
        yield '96 is a fumble at skill 49' => [49, 96, 'fumble'];
        yield '96 is a fumble at skill 1' => [1, 96, 'fumble'];
        yield '99 is a regular success at skill 99' => [99, 99, 'regular'];
        yield '100 is a fumble at skill 99' => [99, 100, 'fumble'];
    }

    #[Test]
    #[DataProvider('edges')]
    public function aRollFallsInTheBandOfItsEdge(int $skill, int $roll, string $band): void
    {
        $result = $this->check('library-use')->perform(new ScriptedRandomNumberGenerator($roll), $this->values(['library-use' => $skill]), null, 0);

        self::assertSame($band, $result->band->key);
    }

    #[Test]
    public function everyCheckHasTheSixBandsInOrderAndRollsAPercentileDie(): void
    {
        $keys = array_map(static fn (Check $check): string => $check->key, $this->release->checks());

        self::assertCount(23, $keys);
        foreach ($this->release->checks() as $check) {
            self::assertSame('1d100', $check->dice?->notation(), $check->key);
            self::assertSame(['critical', 'extreme', 'hard', 'regular', 'failure', 'fumble'], array_map(static fn (\App\Play\Domain\GameSystem\Check\CheckBand $band): string => $band->key, $check->bands), $check->key);
        }
    }

    #[Test]
    public function aCharacteristicCheckReadsItsCharacteristic(): void
    {
        $result = $this->check('pow')->perform(new ScriptedRandomNumberGenerator(60), $this->values(['pow' => 60]), null, 0);

        self::assertSame('regular', $result->band->key);
    }

    #[Test]
    public function theSanityCheckReadsTheCurrentSanityNotTheMaximum(): void
    {
        // A Character at 40 of 99 Sanity: a 55 fails the check although it is under the starting 99.
        $result = $this->check('sanity')->perform(new ScriptedRandomNumberGenerator(55), $this->values(['sanity' => ['current' => 40, 'max' => 99]]), null, 0);

        self::assertSame('failure', $result->band->key);
    }

    private function check(string $key): Check
    {
        return $this->release->check($key) ?? self::fail(\sprintf('No check "%s".', $key));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function values(array $values): SheetValues
    {
        return SheetValues::of($this->release->sheet(), $values, []);
    }
}
