<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Core13\Seeding\DataHandling;

use SBUERK\Seeder\Seeding\DataHandling\SiteConfigurationWriterInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use TYPO3\CMS\Core\Configuration\SiteWriter;

/**
 * TYPO3 v13 implementation of {@see SiteConfigurationWriterInterface}.
 *
 * It is a pure delegation, and that is the whole point of the split: on v13 the
 * two methods live on `TYPO3\CMS\Core\Configuration\SiteWriter`, a container
 * service registered under its class name (13.4:
 * `Core/Classes/ServiceProvider.php::getSiteWriter()`), so it is injected by
 * type and no path handling is needed here. The class does not exist on TYPO3
 * v12, which is why naming it lives in this directory rather than in
 * `Classes/`.
 *
 * Nothing has to be done about cache invalidation here: `SiteWriter::write()`
 * dispatches a `SiteConfigurationChangedEvent` (13.4: SiteWriter.php:139) and
 * `SiteConfiguration` and `SiteFinder` both listen to it (13.4:
 * SiteConfiguration.php:301, SiteFinder.php:117). The v12 implementation has to
 * arrange that itself.
 *
 * Only the `Core13/` directory is registered in the dependency injection
 * container when running on TYPO3 v13, see `Configuration/Services.php`.
 * `#[AsAlias]` makes this class the default implementation of the interface, so
 * consumers type hint the interface and receive the implementation matching the
 * running TYPO3 version. The alias is public because the functional test
 * fetches it from the container to assert that wiring.
 *
 * The class is plain `final`. Readonly classes are PHP 8.2 and this branch
 * supports PHP 8.1 for TYPO3 v12; the rule is branch wide rather than per
 * directory, so the single property is marked `readonly` individually. See
 * `docs/architecture/class-design.md`.
 *
 * @todo Remove together with {@see SiteConfigurationWriterInterface} when TYPO3
 *       v12 support is dropped, and inject `SiteWriter` back into
 *       {@see \SBUERK\Seeder\Seeding\DataHandling\SiteConfigurationSeeder}.
 *
 * @internal Part of the seeding implementation, not public API.
 */
#[AsAlias(id: SiteConfigurationWriterInterface::class, public: true)]
final class SiteConfigurationWriter implements SiteConfigurationWriterInterface
{
    public function __construct(
        private readonly SiteWriter $siteWriter,
    ) {}

    public function write(string $siteIdentifier, array $configuration): void
    {
        $this->siteWriter->write($siteIdentifier, $configuration);
    }

    public function writeSettings(string $siteIdentifier, array $settings): void
    {
        $this->siteWriter->writeSettings($siteIdentifier, $settings);
    }
}
