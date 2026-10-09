<?php

declare(strict_types=1);

namespace App\Tests\Unit\Studio\Application;

use App\Studio\Application\CheckGameSystemRelease;
use App\Studio\Application\CheckGameSystemReleaseHandler;
use App\Studio\Application\GameSystemReleaseCheck;
use App\Studio\Domain\Release\InvalidReleaseContent;
use App\Tests\Support\Studio\ReleaseArrays;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CheckGameSystemRelease::class)]
#[CoversClass(CheckGameSystemReleaseHandler::class)]
#[CoversClass(GameSystemReleaseCheck::class)]
final class CheckGameSystemReleaseHandlerTest extends TestCase
{
    #[Test]
    public function itReturnsTheAuthoringWarningsOfValidContent(): void
    {
        $check = (new CheckGameSystemReleaseHandler())(new CheckGameSystemRelease(ReleaseArrays::fixture('valid/v2-forced-scene-without-relief')));

        self::assertSame('warning-heist', $check->gameSystemKey);
        self::assertSame(2, $check->schemaVersion);
        self::assertSame(['flows[0].phases[1].worldTurn[1]: nextScene firefight does not lower tracker alarm; the consequence may fire every turn'], $check->warnings);
    }

    #[Test]
    public function itReturnsNoWarningsForContentWithout(): void
    {
        $check = (new CheckGameSystemReleaseHandler())(new CheckGameSystemRelease(ReleaseArrays::fixture('valid/contract-doc-example')));

        self::assertSame(1, $check->schemaVersion);
        self::assertSame([], $check->warnings);
    }

    #[Test]
    public function itRejectsInvalidContent(): void
    {
        $this->expectException(InvalidReleaseContent::class);
        $this->expectExceptionMessageIsOrContains('flow.steps[0].key: ');

        (new CheckGameSystemReleaseHandler())(new CheckGameSystemRelease(ReleaseArrays::fixture('invalid/structural/bad-step-key')));
    }
}
