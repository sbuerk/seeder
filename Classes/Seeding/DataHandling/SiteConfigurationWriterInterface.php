<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\DataHandling;

use TYPO3\CMS\Core\Configuration\Exception\SiteConfigurationWriteException;

/**
 * Writes a site configuration into the instance's `config/sites/`, and makes it
 * effective in the running process.
 *
 * The seam exists because the class holding those two methods moved between the
 * supported core versions. TYPO3 v13 split `TYPO3\CMS\Core\Configuration\
 * SiteWriter` out of `SiteConfiguration`; on TYPO3 v12.4 `write()` and
 * `writeSettings()` are still methods of `SiteConfiguration` itself (12.4:
 * SiteConfiguration.php:292 and :308), and `SiteWriter` does not exist there at
 * all. Naming either class in shared code is therefore a fatal error on the
 * other version.
 *
 * What is modelled is the **operation**, not the collaborator. A method handing
 * a `SiteWriter|SiteConfiguration` back to shared code would move the version
 * difference into `Classes/` in a shape no consumer can type hint, and the two
 * classes do not even share an interface to name. With the calls inside the
 * implementations, {@see SiteConfigurationSeeder} sees two fully typed methods
 * and nothing else.
 *
 * ## Why a plain `file_put_contents()` is not the same thing
 *
 * It would produce the same bytes and a different result. Both core writers
 * dispatch `SiteConfigurationBeforeWriteEvent` before serialising, and both
 * invalidate the caches a site is read back through afterwards - which the
 * seeding run itself depends on, because
 * {@see SiteConfigurationSeeder::findUncoveredSiteRoots()} asks `SiteFinder`
 * about the sites this very run has just written.
 *
 * **How** that invalidation happens is the second version difference, and it is
 * the reason the v12 implementation is not a one line delegation. On v13
 * `SiteWriter::write()` ends with a `SiteConfigurationChangedEvent`, which both
 * `SiteConfiguration::siteConfigurationChanged()` and
 * `SiteFinder::siteConfigurationChanged()` listen to (13.4: SiteWriter.php:139,
 * SiteConfiguration.php:301, SiteFinder.php:117). TYPO3 v12 has no such event -
 * the class does not exist in `typo3/cms-core` 12.4 - and `SiteConfiguration::
 * write()` clears its own two caches inline while `SiteFinder` keeps a site list
 * it filled in its constructor. Making the write visible there is part of this
 * operation, and the v12 implementation says how it does it.
 *
 * ## What is deliberately not on this interface
 *
 * `createNewBasicSite()`, `rename()` and `delete()` exist on both versions and
 * none of them is a seeder's decision to make - a seed set that collides with an
 * existing site is refused rather than resolved, see
 * {@see SiteConfigurationSeeder}. `$protectPlaceholders` is not exposed either:
 * it defaults to `false` on both versions and the seeder writes a template
 * verbatim, placeholders included.
 *
 * `SiteConfiguration::getAllSiteConfigurationPaths()`, which the seeder uses to
 * detect that collision, is **not** part of the seam. It is a method of
 * `SiteConfiguration` on v12 and on v13 alike, so shared code can call it
 * directly and pulling it in here would widen the seam for nothing.
 *
 * @todo Delete this interface together with both implementations as soon as
 *       TYPO3 v12 support is dropped, and inject
 *       `TYPO3\CMS\Core\Configuration\SiteWriter` back into
 *       {@see SiteConfigurationSeeder} directly.
 *
 * @internal Part of the seeding implementation, not public API.
 */
interface SiteConfigurationWriterInterface
{
    /**
     * Writes `config.yaml` of the site, creating its directory, and makes the
     * result visible to `SiteConfiguration` and `SiteFinder` in this process.
     *
     * @param array<string, mixed> $configuration The full configuration, as it
     *        is to end up in the file.
     * @throws SiteConfigurationWriteException
     */
    public function write(string $siteIdentifier, array $configuration): void;

    /**
     * Writes `settings.yaml` of the site.
     *
     * It creates no directory and invalidates no cache on either version, so it
     * has to run after {@see self::write()} - see
     * {@see SiteConfigurationSeeder::writeSettings()} for the full reasoning.
     *
     * @param array<string, mixed> $settings
     * @throws SiteConfigurationWriteException
     */
    public function writeSettings(string $siteIdentifier, array $settings): void;
}
