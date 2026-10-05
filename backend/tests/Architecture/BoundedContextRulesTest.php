<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Selector\SelectorInterface;
use PHPat\Test\Builder\BuildStep;
use PHPat\Test\PHPat;

/**
 * DDD boundary rules from ADR 0012, checked by PHPat inside PHPStan (`make qa`).
 *
 * Register every new bounded context in CONTEXTS. Contexts listed in
 * SHARED_KERNEL may be used by every other context.
 */
final class BoundedContextRulesTest
{
    private const string ROOT = 'App';

    /** @var list<non-empty-string> */
    private const array CONTEXTS = ['Play', 'Studio', 'Randomness', 'Identity', 'Admin', 'Shared'];

    /** @var list<non-empty-string> */
    private const array SHARED_KERNEL = ['Randomness', 'Shared'];

    /**
     * Layer rule: a Domain depends only on itself and the shared kernel Domain.
     *
     * @return iterable<string, BuildStep>
     */
    public function testDomainDependsOnlyOnDomain(): iterable
    {
        foreach (self::CONTEXTS as $context) {
            yield $context => PHPat::rule()
                ->classes(self::layer($context, 'Domain'))
                ->canOnlyDependOn()
                ->classes(self::layer($context, 'Domain'), ...$this->sharedKernelLayers('Domain'))
                ->because(\sprintf('%s\Domain may only use its own Domain and the shared kernel Domain (ADR 0012).', $context));
        }
    }

    /**
     * Layer rule: an Application depends only on Domain (its own and the shared
     * kernel's), Application code, and other contexts' published Application contracts.
     *
     * @return iterable<string, BuildStep>
     */
    public function testApplicationDependsOnlyOnDomainAndApplication(): iterable
    {
        foreach (self::CONTEXTS as $context) {
            yield $context => PHPat::rule()
                ->classes(self::layer($context, 'Application'))
                ->canOnlyDependOn()
                ->classes(
                    self::layer($context, 'Domain'),
                    $this->anyContextLayer('Application'),
                    ...$this->sharedKernelLayers('Domain'),
                )
                ->because(\sprintf('%s\Application may only use Domain and Application code; frameworks belong in Infrastructure (ADR 0012).', $context));
        }
    }

    /**
     * Framework-free Domain: no Symfony, Doctrine or any other vendor code.
     */
    public function testDomainIsFrameworkFree(): BuildStep
    {
        return PHPat::rule()
            ->classes($this->anyContextLayer('Domain'))
            ->canOnlyDependOn()
            ->classes(Selector::inNamespace(self::ROOT))
            ->because('Domain code must not use Symfony\, Doctrine\ or any other vendor namespace (ADR 0012).');
    }

    /**
     * Context isolation: a context never uses another context's Domain or
     * Infrastructure, only its Application. The shared kernel is exempt.
     *
     * @return iterable<string, BuildStep>
     */
    public function testContextsAreIsolated(): iterable
    {
        foreach ($this->isolatedContexts() as $context) {
            $forbidden = [];
            foreach ($this->isolatedContexts() as $other) {
                if ($other !== $context) {
                    $forbidden[] = self::layer($other, 'Domain');
                    $forbidden[] = self::layer($other, 'Infrastructure');
                }
            }

            yield $context => PHPat::rule()
                ->classes(Selector::inNamespace(self::ROOT.'\\'.$context))
                ->shouldNotDependOn()
                ->classes(...$forbidden)
                ->because(\sprintf('%s may use other contexts only through their Application layer (ADR 0012).', $context));
        }
    }

    private static function layer(string $context, string $layer): SelectorInterface
    {
        return Selector::inNamespace(\sprintf('%s\%s\%s', self::ROOT, $context, $layer));
    }

    private function anyContextLayer(string $layer): SelectorInterface
    {
        return Selector::inNamespace(\sprintf('/^%s\\\\[A-Za-z0-9]+\\\\%s(\\\\.*)?$/', self::ROOT, $layer), true);
    }

    /**
     * @return list<SelectorInterface>
     */
    private function sharedKernelLayers(string $layer): array
    {
        return array_map(static fn (string $context): SelectorInterface => self::layer($context, $layer), self::SHARED_KERNEL);
    }

    /**
     * @return list<non-empty-string>
     */
    private function isolatedContexts(): array
    {
        return array_values(array_diff(self::CONTEXTS, self::SHARED_KERNEL));
    }
}
