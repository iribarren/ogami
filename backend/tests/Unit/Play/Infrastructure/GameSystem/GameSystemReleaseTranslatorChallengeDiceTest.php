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
 * Play's anti-corruption layer refuses challenge dice (ADR 0049) until the slice of
 * preset-ironsworn that plays them (a challenge roll: slice 2, a burn: slice 3). A release using
 * them publishes but cannot be played yet. Run against valid/v2-challenge-dice.
 */
#[CoversClass(GameSystemReleaseTranslator::class)]
final class GameSystemReleaseTranslatorChallengeDiceTest extends TestCase
{
    /**
     * @param array<mixed> $content
     */
    private function translate(array $content): GameSystemSnapshot
    {
        /** @var array<string, mixed> $typed */
        $typed = $content;

        return new GameSystemReleaseTranslator()->translate(ReleaseViews::of($typed));
    }

    /**
     * valid/v2-challenge-dice with its challenge dice swapped for plain dice and the steps, which
     * named the burn and the progress roll, left alone.
     *
     * @return array<mixed>
     */
    private function playable(): array
    {
        $content = ReleaseViews::fixtureContent('valid/v2-challenge-dice');
        $content = ReleaseArrays::without($content, 'checks.0.challenge');
        $content = ReleaseArrays::without($content, 'checks.0.push');
        $content = ReleaseArrays::without($content, 'checks.1.challenge');
        $content = ReleaseArrays::with($content, 'checks.1.dice', '1d10');

        return ReleaseArrays::with($content, 'checks.1.modifier', '{edge}');
    }

    #[Test]
    public function theReleaseWithoutChallengeDiceIsPlayable(): void
    {
        $snapshot = $this->translate($this->playable());

        self::assertNotNull($snapshot->check('edge'));
        self::assertNotNull($snapshot->check('fulfill'));
    }

    /**
     * @return iterable<string, array{string, mixed, string}> the path set on the playable release, its value and the refusal
     */
    public static function unplayedParts(): iterable
    {
        yield 'an action roll' => ['checks.0.challenge', ['count' => 2, 'sides' => 10, 'momentum' => 'momentum'], 'checks[0].challenge: challenge dice are not played yet (ADR 0049).'];
        yield 'a progress roll' => ['checks.1.challenge', ['count' => 2, 'sides' => 10], 'checks[1].challenge: challenge dice are not played yet (ADR 0049).'];
        yield 'a challenge of the wrong shape' => ['checks.1.challenge', 2, 'checks[1].challenge: challenge dice are not played yet (ADR 0049).'];
        yield 'a null challenge' => ['checks.1.challenge', null, 'checks[1].challenge: challenge dice are not played yet (ADR 0049).'];
    }

    #[Test]
    #[DataProvider('unplayedParts')]
    public function itRefusesChallengeDiceItDoesNotPlayYet(string $path, mixed $value, string $message): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains($message);

        $this->translate(ReleaseArrays::with($this->playable(), $path, $value));
    }

    #[Test]
    public function itRefusesTheWholeFixtureAtTheFirstChallenge(): void
    {
        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('checks[0].challenge: challenge dice are not played yet (ADR 0049).');

        $this->translate(ReleaseViews::fixtureContent('valid/v2-challenge-dice'));
    }

    #[Test]
    public function aBurnWithoutChallengeDiceIsStillAnUnknownPush(): void
    {
        $content = ReleaseArrays::with($this->playable(), 'checks.0.push', ['label' => 'Burn momentum']);

        $this->expectException(InvalidGameSystemRelease::class);
        $this->expectExceptionMessageIsOrContains('checks[0].push');

        $this->translate($content);
    }
}
