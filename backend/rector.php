<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/public',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/config/reference.php',
        __DIR__.'/config/bundles.php',
        __DIR__.'/config/preload.php',
        // The dates of these entities must stay writable: Doctrine refreshes locked rows in place.
        ReadOnlyPropertyRector::class => [
            __DIR__.'/src/Identity/Domain/User.php',
            __DIR__.'/src/Identity/Domain/Invitation.php',
            __DIR__.'/src/Identity/Domain/PasswordReset.php',
        ],
    ])
    ->withCache(__DIR__.'/var/cache/rector')
    ->withPhpSets()
    ->withComposerBased(symfony: true, doctrine: true, phpunit: true)
    ->withSymfonyContainerXml(__DIR__.'/var/cache/dev/App_KernelDevDebugContainer.xml')
    ->withAttributesSets()
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true);
