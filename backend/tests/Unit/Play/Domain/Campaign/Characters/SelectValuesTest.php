<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\Characters;

use App\Play\Domain\Campaign\Characters\CharacterValues;
use App\Play\Domain\Campaign\Characters\InvalidCharacter;
use App\Play\Domain\GameSystem\Sheet\SelectField;
use App\Play\Domain\GameSystem\Sheet\SelectOption;
use App\Play\Domain\GameSystem\Sheet\SheetTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The value of a select field (ADR 0048) on a sheet of "stance" (guarded by default, or reckless) and
 * "origin" (coast or hills, no default).
 */
#[CoversClass(SelectField::class)]
#[CoversClass(CharacterValues::class)]
final class SelectValuesTest extends TestCase
{
    #[Test]
    public function aCharacterStartsWithTheDefaultOptionOrNone(): void
    {
        self::assertSame(['stance' => 'guarded', 'origin' => null], CharacterValues::resolve($this->sheet(), []));
    }

    #[Test]
    public function aDeclaredOptionOrNullIsStored(): void
    {
        self::assertSame(['stance' => 'reckless', 'origin' => 'hills'], CharacterValues::resolve($this->sheet(), ['stance' => 'reckless', 'origin' => 'hills']));
        self::assertSame(['stance' => null, 'origin' => null], CharacterValues::resolve($this->sheet(), ['stance' => null]));
    }

    /**
     * @return iterable<string, array{mixed, string}> the given value and the message
     */
    public static function invalidValues(): iterable
    {
        yield 'an unknown option' => ['ghost', 'Field "stance" has no option "ghost".'];
        yield 'a known option with another case' => ['Guarded', 'Field "stance" has no option "Guarded".'];
        yield 'an empty string' => ['', 'Field "stance" has no option "".'];
        yield 'a number' => [1, 'Field "stance" must be an option key or null.'];
        yield 'a list' => [['guarded'], 'Field "stance" must be an option key or null.'];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function aValueThatIsNotAnOptionIsRefused(mixed $given, string $message): void
    {
        $this->expectException(InvalidCharacter::class);
        $this->expectExceptionMessageIsOrContains($message);

        CharacterValues::resolve($this->sheet(), ['stance' => $given]);
    }

    #[Test]
    public function aStoredNullIsWellFormed(): void
    {
        self::assertSame(['origin' => null, 'stance' => 'guarded'], CharacterValues::assertWellFormed(['origin' => null, 'stance' => 'guarded']));
    }

    #[Test]
    public function aFieldWithoutOptionsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SelectField('stance', 'Stance', options: []);
    }

    #[Test]
    public function aDefaultThatIsNotAnOptionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('default that is not one of its options');
        new SelectField('stance', 'Stance', options: [new SelectOption('guarded', 'Guarded')], default: 'ghost');
    }

    #[Test]
    public function aDuplicateOptionKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('lists an option key twice');
        new SelectField('stance', 'Stance', options: [new SelectOption('a', 'A'), new SelectOption('a', 'B')]);
    }

    private function sheet(): SheetTemplate
    {
        return new SheetTemplate([
            new SelectField('stance', 'Stance', options: [new SelectOption('guarded', 'Guarded'), new SelectOption('reckless', 'Reckless')], default: 'guarded'),
            new SelectField('origin', 'Origin', options: [new SelectOption('coast', 'Coast'), new SelectOption('hills', 'Hills')]),
        ]);
    }
}
