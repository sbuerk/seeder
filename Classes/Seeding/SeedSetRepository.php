<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Seeding;

use SBUERK\DataFactory\Seeding\Exception\DuplicateSeedSetIdentifierException;
use SBUERK\DataFactory\Seeding\Exception\InvalidSeedDefinitionException;
use SBUERK\DataFactory\Seeding\Exception\SeedSetNotFoundException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * Finds the seed sets the active packages of this installation provide.
 *
 * A seed set is a directory `Configuration/DataFactory/<name>/` with a `config.yml`
 * in it. Discovery walks the **active** packages, so a set is available exactly
 * when the extension shipping it is installed *and* activated - there is no
 * configured path list, and nothing outside a package directory is ever
 * scanned. An extension without a `Configuration/DataFactory/` directory is skipped,
 * which is the normal case for all but a handful of packages.
 *
 * ## Discovery reads metadata, it does not parse the set
 *
 * A set is read here with {@see Yaml::parseFile()} and only its `identifier`,
 * `title` and `description` are looked at. It is deliberately *not* handed to
 * {@see \SBUERK\DataFactory\Seeding\Parser\SeedDefinitionParser}, which would follow
 * the `imports` of the set, walk its page tree and validate every record and
 * every file of it.
 *
 * Parsing in full to show a title would make `data-factory:list` as fragile as the
 * least well maintained set in the installation: one set with a typo in a page
 * record, and *no* set can be listed any more - including the ones that are
 * fine, and including the listing an integrator would use to find out which
 * sets exist at all. Listing therefore costs one `yaml_parse` per set and
 * validating a set is what `data-factory:import` does, to the set it was asked for.
 *
 * The three keys have to be declared in the `config.yml` of the set itself and
 * not pulled in through an `imports`, which is what makes reading them without
 * the importing loader correct. That is not a restriction worth regretting: the
 * identity of a set is the one thing that cannot sensibly live somewhere else.
 *
 * ## The order is part of the contract
 *
 * Sets are returned in **discovery order**: the active packages in the order
 * {@see PackageManager::getActivePackages()} returns them, which is the order
 * the installation itself loads them in, and within one package the set
 * directories sorted by name with {@see SORT_STRING}, so the result does not
 * depend on the order the file system happens to hand out directory entries.
 *
 * Sorting the result by identifier instead was rejected: identifiers are not
 * unique until they have been checked to be, and sorting by a key that may
 * occur twice needs a tie breaker that would have to be the package order
 * anyway. Discovery order also puts a set next to the package it came from,
 * which is what a duplicate report is about.
 *
 * The service is stateless: every call re-reads. Should that ever be measurably
 * too slow, it is cached through the TYPO3 caching framework - never into a
 * property, which would make the result depend on how long the request that
 * asked for it had been running.
 *
 * @internal Part of the seeding implementation, not public API.
 */
final readonly class SeedSetRepository
{
    /**
     * Relative to the package path. Not configurable: a set is found where the
     * format says it is, or the format has no meaning.
     */
    private const SET_DIRECTORY = 'Configuration/DataFactory';

    /**
     * The entry point of a set. A directory below {@see self::SET_DIRECTORY}
     * without one is not a seed set and is passed over without a word - that is
     * what lets a set ship additional directories next to its own.
     */
    private const ENTRY_FILE = 'config.yml';

    private const IDENTIFIER = 'identifier';
    private const TITLE = 'title';
    private const DESCRIPTION = 'description';

    public function __construct(
        private PackageManager $packageManager,
    ) {}

    /**
     * Every seed set of the installation, in discovery order.
     *
     * Duplicated identifiers are *kept*: this is what was found, and dropping
     * one of two colliding sets here would hide the collision from the report
     * that exists to show it. Use {@see self::findDuplicates()} to detect one
     * and {@see self::findByIdentifier()} to resolve a single set safely.
     *
     * @return list<SeedSet>
     * @throws InvalidSeedDefinitionException When a `config.yml` cannot be read
     *         or does not declare an identifier and a title. Such a set cannot
     *         be named, so it cannot be listed, skipped or reported either.
     */
    public function findAll(): array
    {
        $seedSets = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            foreach ($this->findConfigurationFiles($package) as $configurationFile) {
                $seedSets[] = $this->readSeedSet($package, $configurationFile);
            }
        }

        return $seedSets;
    }

    /**
     * The one seed set declaring this identifier.
     *
     * @throws SeedSetNotFoundException When no active package provides it.
     * @throws DuplicateSeedSetIdentifierException When more than one does. A
     *         collision is never resolved by letting a provider win.
     * @throws InvalidSeedDefinitionException See {@see self::findAll()}.
     */
    public function findByIdentifier(string $identifier): SeedSet
    {
        $matches = [];
        foreach ($this->findAll() as $seedSet) {
            if ($seedSet->identifier === $identifier) {
                $matches[] = $seedSet;
            }
        }

        if ($matches === []) {
            throw new SeedSetNotFoundException(
                sprintf(
                    'No active extension provides a seed set with the identifier "%s".',
                    $identifier,
                ),
                1787074410,
            );
        }
        if (count($matches) > 1) {
            throw new DuplicateSeedSetIdentifierException(
                sprintf(
                    'The seed set identifier "%s" is provided by more than one extension: %s.',
                    $identifier,
                    $this->describeProviders($matches),
                ),
                1787074411,
            );
        }

        return $matches[0];
    }

    /**
     * The identifiers more than one active package provides, each with all of
     * its providers in discovery order.
     *
     * Keyed by identifier, and only collisions are in it - an installation
     * without one returns an empty array, which is the case a caller checks
     * for.
     *
     * @param list<SeedSet>|null $seedSets The sets to look at. A caller that
     *        has already read them passes them in, so that the listing and the
     *        duplicate report of one command run cannot disagree about what is
     *        installed. `null` re-reads.
     * @return array<string, list<SeedSet>>
     * @throws InvalidSeedDefinitionException See {@see self::findAll()}.
     */
    public function findDuplicates(?array $seedSets = null): array
    {
        /** @var array<array-key, list<SeedSet>> $byIdentifier */
        $byIdentifier = [];
        foreach ($seedSets ?? $this->findAll() as $seedSet) {
            $byIdentifier[$seedSet->identifier][] = $seedSet;
        }

        $duplicates = [];
        foreach ($byIdentifier as $identifier => $providers) {
            if (count($providers) > 1) {
                $duplicates[(string)$identifier] = $providers;
            }
        }

        return $duplicates;
    }

    /**
     * Names the providers of a set of colliding seed sets, extension key first
     * and the file that declares the identifier behind it.
     *
     * The file is part of the message on purpose: two extensions colliding is
     * the obvious case, but one extension colliding with *itself* over two set
     * directories is the more likely mistake, and the extension key alone would
     * then be printed twice and explain nothing.
     *
     * @param list<SeedSet> $seedSets
     */
    private function describeProviders(array $seedSets): string
    {
        return implode(', ', array_map(
            static fn(SeedSet $seedSet): string => sprintf('%s (%s)', $seedSet->extensionKey, $seedSet->configFile),
            $seedSets,
        ));
    }

    /**
     * The `config.yml` files below the `Configuration/DataFactory/` of one package,
     * sorted by path.
     *
     * `glob()` sorts by itself, but the sort is explicit here because the order
     * is a documented property of this class and not something to leave to a
     * default. `SORT_STRING` keeps it byte wise, so a locale cannot reorder a
     * listing.
     *
     * @return list<string>
     */
    private function findConfigurationFiles(PackageInterface $package): array
    {
        $directory = rtrim($package->getPackagePath(), '/') . '/' . self::SET_DIRECTORY;
        if (!is_dir($directory)) {
            return [];
        }

        $configurationFiles = glob($directory . '/*/' . self::ENTRY_FILE);
        if ($configurationFiles === false) {
            return [];
        }
        sort($configurationFiles, SORT_STRING);

        return $configurationFiles;
    }

    /**
     * @throws InvalidSeedDefinitionException
     */
    private function readSeedSet(PackageInterface $package, string $configurationFile): SeedSet
    {
        $metadata = $this->readMetadata($configurationFile);

        $identifier = $metadata[self::IDENTIFIER] ?? null;
        if (!is_string($identifier) || $identifier === '') {
            throw new InvalidSeedDefinitionException(
                sprintf('The seed set "%s" has no "identifier".', $configurationFile),
                1787074403,
            );
        }

        $title = $metadata[self::TITLE] ?? null;
        if (!is_string($title) || $title === '') {
            throw new InvalidSeedDefinitionException(
                sprintf('The seed set "%s" has no "title".', $configurationFile),
                1787074404,
            );
        }

        // "description:" with nothing behind it decodes to null, which the null
        // coalescing operator treats exactly like an absent key.
        $description = $metadata[self::DESCRIPTION] ?? '';
        if (!is_string($description)) {
            throw new InvalidSeedDefinitionException(
                sprintf('The "description" of the seed set "%s" is not a string.', $configurationFile),
                1787074405,
            );
        }

        $packageName = $package->getValueFromComposerManifest('name');

        return new SeedSet(
            identifier: $identifier,
            title: $title,
            description: $description,
            packageName: is_string($packageName) ? $packageName : '',
            extensionKey: $package->getPackageKey(),
            basePath: dirname($configurationFile),
            configFile: $configurationFile,
        );
    }

    /**
     * @return array<array-key, mixed>
     * @throws InvalidSeedDefinitionException
     */
    private function readMetadata(string $configurationFile): array
    {
        try {
            $content = Yaml::parseFile($configurationFile);
        } catch (ParseException $exception) {
            throw new InvalidSeedDefinitionException(
                sprintf('The seed set "%s" is not readable YAML: %s', $configurationFile, $exception->getMessage()),
                1787074401,
                $exception,
            );
        }

        if (!is_array($content)) {
            throw new InvalidSeedDefinitionException(
                sprintf('The seed set "%s" is not a map.', $configurationFile),
                1787074402,
            );
        }

        return $content;
    }
}
