<?php

declare(strict_types=1);

namespace App\Studio\Domain\Release\Version2;

use App\Studio\Domain\Release\InvalidReleaseContent;

/**
 * Runs the validation stages of a release. Failing fast, the first InvalidReleaseContent leaves the
 * stage; collecting, it is kept and the stage gives null, so the caller skips the stages that read
 * its output and runs the others.
 *
 * @internal used by ReleaseVersion2 only
 */
final class Stages
{
    /** @var list<InvalidReleaseContent> */
    private array $errors = [];

    public function __construct(private readonly bool $collect)
    {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $stage
     *
     * @return T|null null when the stage failed and the errors are being collected
     *
     * @throws InvalidReleaseContent when not collecting
     */
    public function run(\Closure $stage): mixed
    {
        try {
            return $stage();
        } catch (InvalidReleaseContent $error) {
            if (!$this->collect) {
                throw $error;
            }

            $this->errors[] = $error;

            return null;
        }
    }

    public function collecting(): bool
    {
        return $this->collect;
    }

    /**
     * @param list<InvalidReleaseContent> $errors
     */
    public function add(array $errors): void
    {
        $this->errors = [...$this->errors, ...$errors];
    }

    /**
     * @return list<InvalidReleaseContent> in the order the stages ran
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
