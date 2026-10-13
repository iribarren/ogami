<?php

declare(strict_types=1);

namespace App\Tests\Unit\Formula\Domain;

use App\Formula\Domain\DependencyOrder;
use App\Formula\Domain\Formula;
use App\Formula\Domain\InvalidFormula;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DependencyOrder::class)]
#[CoversClass(InvalidFormula::class)]
final class DependencyOrderTest extends TestCase
{
    #[Test]
    public function itKeepsInputOrderWhenNothingDependsOnAnything(): void
    {
        $order = DependencyOrder::of($this->formulas(['c' => '1', 'a' => '2', 'b' => '{str}']));

        self::assertSame(['c', 'a', 'b'], $order);
    }

    #[Test]
    public function itPutsDependenciesBeforeTheirDependents(): void
    {
        $order = DependencyOrder::of($this->formulas(['total' => '{bonus}+{base}', 'bonus' => '{base}*2', 'base' => '3']));

        self::assertSame(['base', 'bonus', 'total'], $order);
    }

    #[Test]
    public function itEmitsTheEarliestReadyKeyFirst(): void
    {
        $order = DependencyOrder::of($this->formulas(['a' => '{c}', 'b' => '1', 'c' => '2', 'd' => '3']));

        self::assertSame(['b', 'c', 'a', 'd'], $order);
    }

    #[Test]
    public function itIgnoresReferencesToKeysOutsideTheMapAndNonFieldReferences(): void
    {
        $order = DependencyOrder::of($this->formulas([
            'a' => '{str}+{hp.max}+{tracker:b}',
            'b' => '{a.max}+{missing}',
        ]));

        self::assertSame(['a', 'b'], $order);
    }

    #[Test]
    public function itOrdersAnEmptyMap(): void
    {
        self::assertSame([], DependencyOrder::of([]));
    }

    #[Test]
    public function itRejectsASelfReference(): void
    {
        $this->expectException(InvalidFormula::class);
        $this->expectExceptionMessageIsOrContains('a -> a');

        DependencyOrder::of($this->formulas(['a' => '{a}+1']));
    }

    #[Test]
    public function itNamesTheCycleNotTheKeysLeadingIntoIt(): void
    {
        $this->expectException(InvalidFormula::class);
        $this->expectExceptionMessageIsOrContains('b -> c -> d -> b');

        DependencyOrder::of($this->formulas(['a' => '{b}', 'b' => '{c}', 'c' => '{d}', 'd' => '{b}', 'e' => '1']));
    }

    /**
     * @param array<string, string> $sources
     *
     * @return array<string, Formula>
     */
    private function formulas(array $sources): array
    {
        return array_map(Formula::fromString(...), $sources);
    }
}
