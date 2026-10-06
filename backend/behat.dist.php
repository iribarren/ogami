<?php

declare(strict_types=1);

use App\Tests\Behat\IdentityContext;
use App\Tests\Behat\PlayContext;
use App\Tests\Behat\RandomnessContext;
use App\Tests\Behat\StudioContext;
use Behat\Config\Config;
use Behat\Config\Profile;
use Behat\Config\Suite;

// Living specification in the ubiquitous language (ADR 0009, docs/domain/glossary.md).
// One suite per bounded context; domain-only suites need no Symfony kernel.
return new Config()
    ->withProfile(new Profile('default')
        ->withSuite(new Suite('randomness')
            ->withPaths('%paths.base%/features/randomness')
            ->withContexts(RandomnessContext::class))
        ->withSuite(new Suite('identity')
            ->withPaths('%paths.base%/features/identity')
            ->withContexts(IdentityContext::class))
        ->withSuite(new Suite('studio')
            ->withPaths('%paths.base%/features/studio')
            ->withContexts(StudioContext::class))
        ->withSuite(new Suite('play')
            ->withPaths('%paths.base%/features/play')
            ->withContexts(PlayContext::class)));
