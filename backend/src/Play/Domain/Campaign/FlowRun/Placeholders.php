<?php

declare(strict_types=1);

namespace App\Play\Domain\Campaign\FlowRun;

/**
 * Renders the placeholders of a step's title, prompt or tip and of a sceneTitle Effect: a "{…}"
 * matching \{[a-z]+(:[a-z0-9-]+)?\}. "{tracker:key}" is the Tracker's current value,
 * "{step:key}" the latest answer text of that step ("—" while unanswered) and "{answer}" the
 * answer text of the step whose Effect it renders ("—" without one), "{picked}" the names of the
 * items picked in the current Scene joined with ", " (empty before any pick) and "{fact:key}" the value of a Fact slot (empty while it is
 * unfilled). Other braces are text. A placeholder the release contract does not allow (schema
 * version 2) cannot be rendered, nor can one naming a Tracker or Fact slot the release lacks.
 */
final readonly class Placeholders
{
    private const string PATTERN = '/\{([a-z]+)(?::([a-z0-9-]+))?\}/';

    private const string UNANSWERED = '—';

    /**
     * @param array<string, int>    $trackers the current value of every Tracker of the pinned release, by key
     * @param array<string, string> $answers  the latest answer text by step key
     * @param array<string, string> $facts    the value of every Fact slot of the pinned release, by slot key ('' while unfilled); required, so no caller can omit it
     * @param ?string               $answer   the answer text "{answer}" stands for, null for none
     * @param list<string>          $picked   the current names of the items "{picked}" stands for
     */
    public function __construct(
        private array $trackers,
        private array $answers,
        private array $facts,
        private ?string $answer = null,
        private array $picked = [],
    ) {
    }

    /**
     * @throws \LogicException when a placeholder is not one of the contract's, or names an unknown Tracker or Fact slot
     */
    public function render(string $text): string
    {
        return (string) preg_replace_callback(self::PATTERN, $this->value(...), $text);
    }

    /**
     * @param array<array-key, string> $match the whole placeholder, its namespace and its key
     */
    private function value(array $match): string
    {
        $key = $match[2] ?? '';

        return match (true) {
            'tracker' === $match[1] && '' !== $key => (string) ($this->trackers[$key] ?? throw new \LogicException(\sprintf('The pinned release has no Tracker "%s".', $key))),
            'step' === $match[1] && '' !== $key => $this->answers[$key] ?? self::UNANSWERED,
            'fact' === $match[1] && '' !== $key => $this->facts[$key] ?? throw new \LogicException(\sprintf('The pinned release has no Fact slot "%s".', $key)),
            'picked' === $match[1] && '' === $key => implode(', ', $this->picked),
            'answer' === $match[1] && '' === $key => $this->answer ?? self::UNANSWERED,
            default => throw new \LogicException(\sprintf('Placeholder %s is not supported.', $match[0])),
        };
    }
}
