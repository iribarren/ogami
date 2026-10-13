<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Domain\Release;

use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Studio\Domain\Release\ReleaseContent;
use App\Studio\Domain\Release\Version2\Checks;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The checks of schema version 2 (ADR 0024): additive, so releases without checks keep their
 * canonical form and hash; the agreement test covers each rule, this one the canonical form.
 */
#[CoversClass(ReleaseContent::class)]
#[CoversClass(Checks::class)]
final class ReleaseContentChecksTest extends TestCase
{
    #[Test]
    public function checksKeepTheirAuthoredOrderAndLeaveNullsOut(): void
    {
        $release = ReleaseArrays::fixture('valid/v2-checks');
        $release = ReleaseArrays::with($release, 'checks.1.hint', null);
        $release = ReleaseArrays::with($release, 'checks.1.modifier', null);
        $release = ReleaseArrays::with($release, 'checks.1.difficulty', ['label' => 'Target', 'default' => 5, 'min' => null, 'max' => null]);

        /** @var array{checks: list<array<string, mixed>>} $content */
        $content = ReleaseContent::fromArray($release)->toArray();

        self::assertSame(['skill', 'luck'], array_column($content['checks'], 'key'));
        self::assertSame(['key', 'label', 'dice', 'difficulty', 'bands'], array_keys($content['checks'][1]));
        self::assertSame(['label' => 'Target', 'default' => 5], $content['checks'][1]['difficulty']);
        self::assertSame('checks', array_key_last(ReleaseContent::fromArray($release)->toArray()));
    }

    #[Test]
    public function aStepDifficultyAtTheBoundOfTheCheckRangeIsValid(): void
    {
        foreach ([9, 29] as $difficulty) {
            $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-checks'), 'sceneTypes.0.play.0.difficulty', $difficulty);

            /** @var array{sceneTypes: list<array{play: list<array<string, mixed>>}>} $content */
            $content = ReleaseContent::fromArray($release)->toArray();

            self::assertSame($difficulty, $content['sceneTypes'][0]['play'][0]['difficulty']);
        }
    }

    #[Test]
    public function aStepDifficultyAboveTheMaxOfTheCheckRangeIsRefused(): void
    {
        $release = ReleaseArrays::with(ReleaseArrays::fixture('valid/v2-checks'), 'sceneTypes.0.play.0.difficulty', 30);

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('sceneTypes[0].play[0].difficulty: must be at most 29, the difficulty max of check "skill", 30 given');

        ReleaseContent::fromArray($release);
    }

    #[Test]
    public function checksAreStillReservedInSchemaVersion1(): void
    {
        $release = ReleaseArrays::fixture('valid/v2-checks');
        $v1 = ['schemaVersion' => 1, 'gameSystem' => $release['gameSystem'], 'oracles' => $release['oracles'], 'flow' => ['steps' => []], 'sheet' => [], 'checks' => $release['checks']];

        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('checks: not supported in schema version 1, must be []');

        ReleaseContent::fromArray($v1);
    }
}
