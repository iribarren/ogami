<?php

declare(strict_types=1);

namespace App\Tests\Unit\Play\Domain\Campaign\FlowRun;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The presets that guide play name the outcome of every band of their roll steps, so the journal
 * can say what the dice decided.
 */
final class PresetBandLabelsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function presets(): iterable
    {
        yield 'mythic-style' => ['mythic-style'];
        yield 'night-job' => ['night-job'];
    }

    #[Test]
    #[DataProvider('presets')]
    public function everyBandOfARollStepHasALabel(string $preset): void
    {
        $content = json_decode((string) file_get_contents(__DIR__.'/../../../../../../presets/'.$preset.'.json'), true, flags: \JSON_THROW_ON_ERROR);

        $unlabelled = [];
        $rolls = 0;
        /** @var \Closure(mixed): void $walk */
        $walk = static function (mixed $node) use (&$walk, &$unlabelled, &$rolls): void {
            if (!\is_array($node)) {
                return;
            }

            if ('roll' === ($node['kind'] ?? null)) {
                ++$rolls;
                $bands = \is_array($node['bands'] ?? null) ? $node['bands'] : [];
                foreach ($bands as $index => $band) {
                    $label = \is_array($band) ? ($band['label'] ?? null) : null;
                    if (!\is_string($label) || '' === trim($label)) {
                        $unlabelled[] = \sprintf('%s.bands[%d]', \is_string($node['key'] ?? null) ? $node['key'] : '?', (int) $index);
                    }
                }
            }

            array_map($walk, $node);
        };
        $walk($content);

        self::assertGreaterThan(0, $rolls);
        self::assertSame([], $unlabelled);
    }
}
