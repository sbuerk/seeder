<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Core\Information\Typo3Version;

return static function (
    ContainerConfigurator $configurator,
    ContainerBuilder $builder,
): void {
    $services = $configurator->services();

    // Default configuration: autowire and autoconfigure, keep services private.
    // Services are published and wired through Symfony dependency injection
    // attributes on the classes themselves (#[AsAlias], #[Autoconfigure],
    // #[Autowire], …), not through this file.
    $services->defaults()
        ->autowire()
        ->autoconfigure()
        ->private();

    // Core version independent classes.
    $services->load(
        'SBUERK\\DataFactory\\',
        __DIR__ . '/../Classes/*',
    );

    // Core version aware classes: `Core12/` on TYPO3 v12, `Core13/` on TYPO3
    // v13. Both directories are autoloaded by composer, but only the one
    // matching the running core version is registered as services, so an
    // implementation may safely use API that exists in its core version only.
    //
    // The `is_dir()` guard covers a core version whose directory is absent or
    // holds no implementation of its own. Both carry one on this branch; an
    // empty or absent directory registers nothing and is not an error, here or
    // in `Classes/` above.
    $coreMajorVersion = (new Typo3Version())->getMajorVersion();
    $coreAwareDirectory = sprintf('%s/../Core%d', __DIR__, $coreMajorVersion);
    if (is_dir($coreAwareDirectory)) {
        $services->load(
            sprintf('SBUERK\\DataFactory\\Core%d\\', $coreMajorVersion),
            $coreAwareDirectory . '/*',
        );
    }
};
