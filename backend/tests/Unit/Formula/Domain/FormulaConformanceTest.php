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

/**
 * Runs backend/contracts/formula/conformance.json, the vectors the TypeScript evaluator runs too (ADR 0023).
 */
#[CoversClass(Formula::class)]
#[CoversClass(Reference::class)]
#[CoversClass(InvalidFormula::class)]
#[CoversClass(FormulaEvaluationFailed::class)]
#[CoversNamespace('App\Formula\Domain\Expression')]
final class FormulaConformanceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, int>, int|string}>
     */
    public static function evaluateCases(): iterable
    {
        foreach (self::vectors()['evaluate'] as $index => $case) {
            $expected = \array_key_exists('result', $case) ? $case['result'] : $case['error'];

            yield \sprintf('#%d %s', $index, json_encode($case['formula'], \JSON_UNESCAPED_UNICODE)) => [
                $case['formula'],
                $case['values'] ?? [],
                $expected,
            ];
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function referenceCases(): iterable
    {
        foreach (self::vectors()['references'] as $case) {
            yield $case['formula'] => [$case['formula'], $case['references']];
        }
    }

    /**
     * @param array<string, int> $values
     */
    #[Test]
    #[DataProvider('evaluateCases')]
    public function itEvaluatesLikeTheConformanceVectors(string $source, array $values, int|string $expected): void
    {
        $resolver = new readonly class($values) implements ValueResolver {
            /**
             * @param array<string, int> $values
             */
            public function __construct(private array $values)
            {
            }

            public function value(Reference $reference): int
            {
                // PHP turns numeric JSON keys such as "123" into integer keys.
                return $this->values[$reference->toString()] ?? throw FormulaEvaluationFailed::unknownReference($reference);
            }
        };

        if (\is_int($expected)) {
            self::assertSame($expected, Formula::fromString($source)->evaluate($resolver));

            return;
        }

        if ('syntax' === $expected) {
            $this->expectException(InvalidFormula::class);
            Formula::fromString($source);

            return;
        }

        try {
            Formula::fromString($source)->evaluate($resolver);
            self::fail('Expected the evaluation to fail with '.$expected);
        } catch (FormulaEvaluationFailed $failure) {
            self::assertSame($expected, $failure->reason);
        }
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('referenceCases')]
    public function itListsReferencesLikeTheConformanceVectors(string $source, array $expected): void
    {
        $references = array_map(
            static fn (Reference $reference): string => $reference->toString(),
            Formula::fromString($source)->references(),
        );

        self::assertSame($expected, $references);
    }

    /**
     * @return array{evaluate: list<array{formula: string, values?: array<string, int>, result: int}|array{formula: string, values?: array<string, int>, error: string}>, references: list<array{formula: string, references: list<string>}>}
     */
    private static function vectors(): array
    {
        $json = file_get_contents(__DIR__.'/../../../../contracts/formula/conformance.json');
        self::assertIsString($json);

        /** @var array{evaluate: list<array{formula: string, values?: array<string, int>, result: int}|array{formula: string, values?: array<string, int>, error: string}>, references: list<array{formula: string, references: list<string>}>} $vectors */
        $vectors = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $vectors;
    }
}
