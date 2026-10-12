<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use App\Play\Domain\Campaign\FlowRun\Placeholders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Placeholders::class)]
final class PlaceholdersTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function texts(): iterable
    {
        yield 'a Tracker value' => ['The alarm is at {tracker:alarm}.', 'The alarm is at 3.'];
        yield 'a negative Tracker value' => ['Mood {tracker:mood-swing}', 'Mood -2'];
        yield 'an answered step' => ['Was {step:plan} worth it?', 'Was In through the roof worth it?'];
        yield 'an unanswered step' => ['Was {step:catch} worth it?', 'Was — worth it?'];
        yield 'the answer' => ['Firefight: {answer}', 'Firefight: Ada'];
        yield 'several, twice' => ['{answer} at {tracker:alarm}, {answer} again', 'Ada at 3, Ada again'];
        yield 'other braces are text' => ['{Tracker:alarm} {tracker:Alarm} { answer } {} {tracker:} {1}', '{Tracker:alarm} {tracker:Alarm} { answer } {} {tracker:} {1}'];
        yield 'no placeholder' => ['Who walks in?', 'Who walks in?'];
    }

    #[Test]
    #[DataProvider('texts')]
    public function aPlaceholderIsRenderedAndOtherBracesStay(string $text, string $rendered): void
    {
        self::assertSame($rendered, new Placeholders(['alarm' => 3, 'mood-swing' => -2], ['plan' => 'In through the roof'], 'Ada')->render($text));
    }

    #[Test]
    public function theAnswerWithoutOneReadsAsUnanswered(): void
    {
        self::assertSame('Then: —', new Placeholders([], [])->render('Then: {answer}'));
    }

    #[Test]
    public function pickedIsTheNamesJoinedAndEmptyBeforeAnyPick(): void
    {
        self::assertSame('Meet Ana, Bea.', new Placeholders([], [], picked: ['Ana', 'Bea'])->render('Meet {picked}.'));
        self::assertSame('Meet .', new Placeholders([], [])->render('Meet {picked}.'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unsupported(): iterable
    {
        yield 'an unknown Tracker' => ['{tracker:heat}', 'The pinned release has no Tracker "heat".'];
        yield 'another namespace' => ['{fact:villain}', 'Placeholder {fact:villain} is not supported.'];
        yield 'a Tracker without a key' => ['{tracker}', 'Placeholder {tracker} is not supported.'];
        yield 'picked with a key' => ['{picked:x}', 'Placeholder {picked:x} is not supported.'];
        yield 'the answer with a key' => ['{answer:plan}', 'Placeholder {answer:plan} is not supported.'];
    }

    #[Test]
    #[DataProvider('unsupported')]
    public function aPlaceholderOutsideTheContractFailsClosed(string $text, string $message): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains($message);

        new Placeholders(['alarm' => 3], [])->render($text);
    }
}
