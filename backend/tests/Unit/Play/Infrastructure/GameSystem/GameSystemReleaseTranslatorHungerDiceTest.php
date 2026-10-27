<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Infrastructure\GameSystem;

use App\Play\Domain\GameSystem\GameSystemSnapshot;
use App\Play\Domain\GameSystem\InvalidGameSystemRelease;
use App\Play\Infrastructure\GameSystem\GameSystemReleaseTranslator;
use App\Tests\Support\Play\ReleaseViews;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Play's anti-corruption layer refuses the parts of ADR 0044 until the slices of
 * preset-vtm-chronicle that play them: critical pairs, Hunger dice and a push cap (slice 2),
 * check step outcomes on a special result and the credits badge (slice 3). A release using them
 * publishes but cannot be played yet. Run against valid/v2-hunger-dice without those parts.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
final class GameSystemReleaseTranslatorHungerDiceTest extends TestCase
{
    /**
     * valid/v2-hunger-dice with every part of ADR 0044 left out.
     *
     * @return array<mixed>
     */
    private function playable(): array
    {
        $content = ReleaseViews::fixtureContent('valid/v2-hunger-dice');
        foreach (['gameSystem.credits.badge', 'checks.0.pool.criticals', 'checks.0.pool.hunger', 'checks.0.push.maxDice', 'checks.1.pool.hunger', 'checks.2.pool.criticals'] as $path) {
            $content = ReleaseArrays::without($content, $path);
        }

        $content = ReleaseArrays::with($content, 'sceneTypes.0.play.0.outcomes', [['band' => 'fail']]);

        return ReleaseArrays::with($content, 'sceneTypes.0.play.1.outcomes', []);
    }

    /**
     * @param array<mixed> $content
     */
    private function translate(array $content): GameSystemSnapshot
    {
        /** @var array<string, mixed> $typed */
        $typed = $content;

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($typed));
    }

    #[Test]
    public function theReleaseWithoutThemIsPlayable(): void
    {
        $snapshot = $this->translate($this->playable());

        self::assertNotNull($snapshot->check('persuade'));
        self::assertNotNull($snapshot->credits());
    }

    /**
     * @return iterable<string, array{string, mixed, string}> the path set on the playable release, its value and the refusal
     */
    public static function unplayedParts(): iterable
    {
        yield 'critical pairs' => ['checks.0.pool.criticals', ['bonus' => 2], 'checks[0].pool.criticals: critical pairs are not played yet (ADR 0044).'];
        yield 'Hunger dice' => ['checks.1.pool.hunger', ['count' => 1], 'checks[1].pool.hunger: Hunger dice are not played yet (ADR 0044).'];
        yield 'a push cap' => ['checks.0.push.maxDice', 3, 'checks[0].push.maxDice: a push cap is not played yet (ADR 0044).'];
        yield 'an outcome on a special result' => ['sceneTypes.0.play.1.outcomes', [['special' => 'bestialFailure', 'next' => 'compulsion']], 'sceneTypes[0].play[1].outcomes[0].special: outcomes on a special result are not played yet (ADR 0044).'];
        yield 'a special beside a band' => ['sceneTypes.0.play.0.outcomes.0.special', 'critical', 'sceneTypes[0].play[0].outcomes[0].special: outcomes on a special result are not played yet (ADR 0044).'];
        yield 'a credits badge' => ['gameSystem.credits.badge', 'dark-pack', 'gameSystem.credits.badge: a credits badge is not played yet (ADR 0044).'];
    }

    #[Test]
    #[DataProvider('unplayedParts')]
    public function itRefusesAPartItDoesNotPlayYet(string $path, mixed $value, string $message): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->translate(ReleaseArrays::with($this->playable(), $path, $value));
    }
}
