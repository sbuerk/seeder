<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Core12\Seeding\DataHandling;

use SBUERK\Seeder\Seeding\DataHandling\SiteConfigurationWriterInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * TYPO3 v12 implementation of {@see SiteConfigurationWriterInterface}.
 *
 * On TYPO3 v12.4 the two methods are still on `SiteConfiguration` itself (12.4:
 * SiteConfiguration.php:308 `write()`, :292 `writeSettings()`), with the same
 * signatures and the same `SiteConfigurationWriteException` (codes 1590487011
 * and 1590487411) the v13 `SiteWriter` throws. `SiteConfiguration` is a
 * container service on v12 as well (12.4: `Core/Classes/ServiceProvider.php:57`
 * plus the `$configPath` argument in `Core/Configuration/Services.yaml`), so it
 * is injected by type. The merge semantics `SiteConfigurationSeeder` refuses an
 * existing identifier over are identical: v12 `write()` loads the existing file
 * unprocessed, diffs it and merges with `ArrayUtility::mergeRecursiveWithOverrule()`
 * exactly as v13 does.
 *
 * ## Why `SiteFinder` is injected here
 *
 * The one behaviour v12 does not provide by itself is making the written
 * configuration visible in the running process.
 *
 * `SiteConfigurationChangedEvent` is a v13 addition - the class does not exist
 * in `typo3/cms-core` 12.4 - so v12 `write()` clears the two caches it owns
 * inline (`$this->firstLevelCache = null` and the `sites-configuration` core
 * cache entry, 12.4: SiteConfiguration.php:343-344) and nothing tells anybody
 * else. `SiteFinder` on v12 is not the runtime cache backed, event driven class
 * of v13: it fills `$sites` and `$mappingRootPageIdToIdentifier` in its
 * constructor (12.4: SiteFinder.php:49-53, :126-132) and never refreshes them
 * unless it is asked to. The instance the seeder holds is the shared container
 * one, built before the first site of the run was written, so
 * `getSiteByPageId()` would answer `SiteNotFoundException` for a root page this
 * run has just covered - and
 * {@see \SBUERK\Seeder\Seeding\DataHandling\SiteConfigurationSeeder::findUncoveredSiteRoots()}
 * would report a site it wrote itself as uncovered.
 *
 * `getAllSites(false)` is the only public way in: it is what re-runs
 * `fetchAllSites()` and rebuilds the root page mapping. Its return value is
 * discarded on purpose - the refresh is the point, not the list. This is the
 * v12 equivalent of what the event listener does on v13, which is why it
 * belongs in this class and not in the shared seeder.
 *
 * `writeSettings()` needs no such follow up. It is not cache invalidating on
 * either version, `settings.yaml` is read through `SiteConfiguration` rather
 * than `SiteFinder`, and the seeder writes it after `write()` anyway.
 *
 * Only the `Core12/` directory is registered in the dependency injection
 * container when running on TYPO3 v12, see `Configuration/Services.php`.
 * `#[AsAlias]` makes this class the default implementation of the interface, so
 * consumers type hint the interface and receive the implementation matching the
 * running TYPO3 version. The alias is public because the functional test
 * fetches it from the container to assert that wiring.
 *
 * The class is plain `final`. Readonly classes are PHP 8.2, and this branch
 * supports PHP 8.1 for TYPO3 v12, so the properties are marked `readonly`
 * individually. See `docs/architecture/class-design.md`.
 *
 * @todo Remove together with {@see SiteConfigurationWriterInterface} when TYPO3
 *       v12 support is dropped.
 *
 * @internal Part of the seeding implementation, not public API.
 */
#[AsAlias(id: SiteConfigurationWriterInterface::class, public: true)]
final class SiteConfigurationWriter implements SiteConfigurationWriterInterface
{
    public function __construct(
        private readonly SiteConfiguration $siteConfiguration,
        private readonly SiteFinder $siteFinder,
    ) {}

    public function write(string $siteIdentifier, array $configuration): void
    {
        $this->siteConfiguration->write($siteIdentifier, $configuration);

        // Not a cache warmup but a cache *reset*: this is the only public entry
        // point of the v12 `SiteFinder` that re-reads the site list, and
        // without it the site just written stays invisible to the injected
        // instance for the rest of the process. See the class docblock.
        $this->siteFinder->getAllSites(false);
    }

    public function writeSettings(string $siteIdentifier, array $settings): void
    {
        $this->siteConfiguration->writeSettings($siteIdentifier, $settings);
    }
}
