<?php

declare(strict_types=1);

namespace App\Tests\Unit\Formula\Domain;

use App\Formula\Domain\Formula;
use App\Formula\Domain\FormulaEvaluationFailed;
use App\Formula\Domain\InvalidFormula;
use App\Formula\Domain\Reference;
use App\Formula\Domain\ValueResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Formula::class)]
#[CoversClass(Reference::class)]
#[CoversClass(InvalidFormula::class)]
#[CoversClass(FormulaEvaluationFailed::class)]
#[CoversNamespace('App\Formula\Domain\Expression')]
final class FormulaTest extends TestCase
{
    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function invalidFormulas(): iterable
    {
        yield 'empty' => ['', 'The formula is empty; expected a formula such as "{str}+1".'];
        yield 'blank' => ["  \n", 'The formula is empty; expected a formula such as "{str}+1".'];
        yield 'too long' => [str_repeat('1+', 100).'1', 'A formula has at most 200 characters, 201 given.'];
        yield 'unexpected character' => ['1 + $', 'Unexpected "$" at position 5;'];
        yield 'unicode digit' => ['1+٣', 'Unexpected "٣" at position 3;'];
        yield 'invalid utf-8' => ["1+\xFF", 'Unexpected "\xFF" at position 3;'];
        yield 'trailing operator' => ['1+', 'Unexpected end of the formula; expected a number, a reference, "min", "max", "-" or "(".'];
        yield 'two numbers' => ['1 2', 'Unexpected "2" at position 3; expected an operator or the end of the formula.'];
        yield 'integer too large' => ['1000001', 'A number in a formula is between 0 and 1000000, 1000001 given.'];
        yield 'integer far too large' => ['99999999999999999999999', 'A number in a formula is between 0 and 1000000, 99999999999999999999999 given.'];
        yield 'unknown identifier' => ['str+1', 'Unknown name "str" at position 1; write field references in braces, as in "{str}"'];
        yield 'upper case function' => ['MIN(1,2)', 'Unknown name "MIN" at position 1;'];
        yield 'empty reference' => ['{}', 'Invalid reference "{}" at position 1;'];
        yield 'reference with space' => ['{ str}', 'Invalid reference "{ str}" at position 1;'];
        yield 'min suffix' => ['{a.min}', 'Invalid reference "{a.min}" at position 1;'];
        yield 'empty tracker' => ['{tracker:}', 'Invalid reference "{tracker:}" at position 1;'];
        yield 'tracker max' => ['{tracker:x.max}', 'Invalid reference "{tracker:x.max}" at position 1;'];
        yield 'empty check' => ['{check:}', 'Invalid reference "{check:}" at position 1;'];
        yield 'check max' => ['{check:x.max}', 'Invalid reference "{check:x.max}" at position 1;'];
        yield 'key too long' => ['{'.str_repeat('a', 65).'}', 'Invalid reference'];
        yield 'unclosed reference' => ['1+{a', 'Unclosed "{" at position 3;'];
        yield 'one argument' => ['min(1)', '"min" takes between 2 and 10 arguments, 1 given.'];
        yield 'eleven arguments' => ['max(1,2,3,4,5,6,7,8,9,10,11)', '"max" takes between 2 and 10 arguments, more than 10 given.'];
        yield 'no arguments' => ['min()', 'Unexpected ")" at position 5;'];
        yield 'trailing comma' => ['min(1,2,)', 'Unexpected ")" at position 9;'];
        yield 'function without parenthesis' => ['min 1', 'Unexpected "1" at position 5; expected "(".'];
        yield 'empty parentheses' => ['()', 'Unexpected ")" at position 2;'];
        yield 'unclosed parenthesis' => ['(1', 'Unexpected end of the formula; expected ")".'];
        yield 'comma outside a call' => ['1,2', 'Unexpected "," at position 2;'];
        yield 'too deep parentheses' => [str_repeat('(', 51).'1'.str_repeat(')', 51), 'A formula nests at most 50 levels deep.'];
        yield 'too deep negations' => [str_repeat('-', 51).'1', 'A formula nests at most 50 levels deep.'];
    }

    /**
     * @param non-empty-string $message
     */
    #[Test]
    #[DataProvider('invalidFormulas')]
    public function itRejectsMalformedFormulasWithAClearMessage(string $source, string $message): void
    {
        try {
            Formula::fromString($source);
            self::fail('Expected an InvalidFormula.');
        } catch (InvalidFormula $invalid) {
            self::assertStringStartsWith($message, $invalid->getMessage());
        }
    }

    #[Test]
    public function itAcceptsTheLimits(): void
    {
        $longest = '0'.str_repeat('+0', 98).'+00';
        $deepest = str_repeat('(', 50).'1'.str_repeat(')', 50);
        $lastKey = '{'.str_repeat('a', 64).'}';

        self::assertSame(200, \strlen($longest));
        self::assertSame($longest, Formula::fromString($longest)->toString());
        self::assertSame($deepest, Formula::fromString($deepest)->toString());
        self::assertSame($lastKey, '{'.Formula::fromString($lastKey)->references()[0]->toString().'}');
        self::assertSame('min(1,2,3,4,5,6,7,8,9,10)', Formula::fromString('min(1,2,3,4,5,6,7,8,9,10)')->toString());
    }

    #[Test]
    public function itKeepsTheTrimmedText(): void
    {
        $formula = Formula::fromString("  {str} - 10 \n");

        self::assertSame('{str} - 10', $formula->toString());
        self::assertSame('{str} - 10', (string) $formula);
    }

    #[Test]
    public function itListsEachReferenceOnceInFirstSeenOrder(): void
    {
        $references = Formula::fromString('{b}+{tracker:b}+{a}+{b}+{b.max}+{tracker:b}')->references();

        self::assertEquals(
            [Reference::field('b'), Reference::tracker('b'), Reference::field('a'), Reference::trackMax('b')],
            $references,
        );
    }

    #[Test]
    public function itListsACheckReferenceApartFromTheSameKeyRead(): void
    {
        self::assertEquals(
            [Reference::check('difficulty'), Reference::field('difficulty')],
            Formula::fromString('{check:difficulty}+{difficulty}+{check:difficulty}')->references(),
        );
    }

    #[Test]
    public function itSpellsReferencesCanonically(): void
    {
        self::assertSame('str', Reference::field('str')->toString());
        self::assertSame('hp.max', Reference::trackMax('hp')->toString());
        self::assertSame('tracker:chaos', Reference::tracker('chaos')->toString());
        self::assertSame('check:difficulty', Reference::check('difficulty')->toString());
        self::assertTrue(Reference::field('a')->equals(Reference::field('a')));
        self::assertFalse(Reference::field('a')->equals(Reference::tracker('a')));
        self::assertFalse(Reference::check('a')->equals(Reference::tracker('a')));
    }

    #[Test]
    public function itRefusesInvalidReferenceKeys(): void
    {
        $this->expectException(InvalidFormula::class);

        Reference::field('Str');
    }

    #[Test]
    public function itAsksTheResolverOnceForEachOccurrenceAndFloorsDivision(): void
    {
        $resolver = new class implements ValueResolver {
            /** @var list<string> */
            public array $asked = [];

            public function value(Reference $reference): int
            {
                $this->asked[] = $reference->toString();

                return -7;
            }
        };

        self::assertSame(-4, Formula::fromString('{a}/2')->evaluate($resolver));
        self::assertSame(['a'], $resolver->asked);
    }

    #[Test]
    public function itReportsTheReasonOfAnEvaluationFailure(): void
    {
        $resolver = new class implements ValueResolver {
            public function value(Reference $reference): int
            {
                throw FormulaEvaluationFailed::unknownReference($reference);
            }
        };

        try {
            Formula::fromString('1+{tracker:chaos}')->evaluate($resolver);
            self::fail('Expected a FormulaEvaluationFailed.');
        } catch (FormulaEvaluationFailed $failure) {
            self::assertSame(FormulaEvaluationFailed::UNKNOWN_REFERENCE, $failure->reason);
            self::assertSame('The formula reads "tracker:chaos", which has no value.', $failure->getMessage());
        }
    }

    #[Test]
    public function itComputesExactIntegersAtTheBounds(): void
    {
        $resolver = new class implements ValueResolver {
            public function value(Reference $reference): int
            {
                return 1_000_000_000;
            }
        };

        self::assertSame(1_000_000_000, Formula::fromString('1000000*1000')->evaluate($resolver));
        self::assertSame(1_000_000_000, Formula::fromString('{a}')->evaluate($resolver));
        self::assertSame(0, Formula::fromString('{a}-{a}')->evaluate($resolver));
        self::assertSame(FormulaEvaluationFailed::OVERFLOW, $this->failureOf('{a}*{a}', $resolver));
        self::assertSame(FormulaEvaluationFailed::OVERFLOW, $this->failureOf('-{a}-1', $resolver));
        self::assertSame(FormulaEvaluationFailed::DIVISION_BY_ZERO, $this->failureOf('{a}/0', $resolver));
    }

    private function failureOf(string $source, ValueResolver $resolver): string
    {
        try {
            Formula::fromString($source)->evaluate($resolver);
        } catch (FormulaEvaluationFailed $failure) {
            return $failure->reason;
        }

        self::fail('Expected a FormulaEvaluationFailed.');
    }
}
