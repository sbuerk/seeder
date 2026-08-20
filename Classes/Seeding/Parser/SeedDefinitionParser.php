<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\Parser;

use SBUERK\Seeder\Seeding\Definition\SeedDefinition;
use SBUERK\Seeder\Seeding\Definition\SeedFile;
use SBUERK\Seeder\Seeding\Definition\SeedSiteConfiguration;
use SBUERK\Seeder\Seeding\Exception\InvalidSeedDefinitionException;
use SBUERK\Seeder\Seeding\Exception\SeedDefinitionNotFoundException;
use TYPO3\CMS\Core\Configuration\Loader\Exception\YamlFileLoadingException;
use TYPO3\CMS\Core\Configuration\Loader\Exception\YamlParseException;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reads the `config.yml` of a seed set, or an already decoded array.
 *
 * `config.yml` describes the **set**, not its records:
 *
 *     identifier: demo
 *     title: 'Demo page tree'
 *     description: 'A page tree with content on it.'
 *     scenarios:
 *       - Pages.yaml
 *       - Content.yaml
 *     files:
 *       - identifier: placeholder
 *         source: 'Files/placeholder.svg'
 *         folder: 'demo'
 *     sites:
 *       - identifier: main
 *         rootPage: 1000
 *
 * The records live in the files named under `scenarios`, in the YAML scenario
 * format of `typo3/testing-framework`, and are read by
 * {@see \SBUERK\Seeder\Seeding\Scenario\ScenarioComposer}. Keeping the two
 * apart is what makes the rule of the scenario format - "the file is the
 * contract, and every key of it is upstream's" - hold: nothing this extension
 * adds is mixed into a scenario file, and nothing a scenario file declares has
 * to be understood here.
 *
 * The key set is **closed**, at the top level and on a site. An unknown key is
 * a typo, not a field of anything; accepting it silently is how `scenario:`
 * instead of `scenarios:` becomes an import that reports success and writes
 * nothing.
 *
 * @internal Part of the seeding implementation, not public API.
 */
final readonly class SeedDefinitionParser
{
    private const IDENTIFIER = 'identifier';
    private const TITLE = 'title';
    private const DESCRIPTION = 'description';
    private const IMPORTS = 'imports';
    private const SCENARIOS = 'scenarios';
    private const FILES = 'files';
    private const SITES = 'sites';
    private const SOURCE = 'source';
    private const FOLDER = 'folder';
    private const NAME = 'name';
    private const STORAGE = 'storage';
    private const ROOT_PAGE = 'rootPage';
    private const TEMPLATE = 'template';
    private const BASE = 'base';

    /**
     * The keys a set descriptor may carry.
     *
     * `imports` is listed although the parser never sees it in practice -
     * `YamlFileLoader` merges and removes it - so that {@see self::parse()} can
     * be called with a raw array carrying one, and rejects the *unknown* rather
     * than the *consumed*.
     */
    private const SET_KEYS = [
        self::IDENTIFIER,
        self::TITLE,
        self::DESCRIPTION,
        self::IMPORTS,
        self::SCENARIOS,
        self::FILES,
        self::SITES,
    ];

    /**
     * The keys a `files` entry may carry - closed for the same reason as
     * {@see self::SET_KEYS}. A file declaration is configuration, not a record:
     * nothing here is written verbatim to anything, so an unknown key can only
     * be a mistake, and `folder:` misspelled as `foldr:` would otherwise put the
     * file in the storage root and report success.
     */
    private const FILE_KEYS = [
        self::IDENTIFIER,
        self::SOURCE,
        self::FOLDER,
        self::NAME,
        self::STORAGE,
    ];

    /**
     * The keys a `sites` entry may carry - closed for the same reason as
     * {@see self::SET_KEYS}. A site is configuration, not a record, so nothing
     * here is written verbatim and an unknown key can only be a mistake.
     */
    private const SITE_KEYS = [
        self::IDENTIFIER,
        self::ROOT_PAGE,
        self::TEMPLATE,
        self::BASE,
    ];

    /**
     * A site identifier becomes the name of a directory below the instance's
     * `config/sites/`, which is what this pattern guards: no separator, no
     * `.` or `..`, nothing that has to be escaped anywhere.
     */
    private const SITE_IDENTIFIER_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]*$/';

    /**
     * Reads the entry file of a seed set, following its `imports`.
     *
     * `imports` is handled by `TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader`,
     * the loader the core reads its own site configurations with. It resolves a
     * resource relative to the file declaring it, accepts `EXT:` paths, and
     * merges an imported list into the importing one instead of replacing it -
     * which is what a descriptor split over several files needs, and it means
     * this extension requires nothing beyond `typo3/cms-core`.
     *
     * Two deliberate deviations from how the core calls it:
     *
     * - **Placeholders are switched off** (`PROCESS_IMPORTS` without
     *   `PROCESS_PLACEHOLDERS`). A `%…%` fragment that happens to name a key of
     *   the descriptor would be substituted with that key's value, and a title
     *   or a description is content that has to arrive as it was written.
     * - **A failing import raises** rather than being logged, through
     *   {@see ThrowOnErrorLogger}, which the loader's own error handling makes
     *   necessary.
     *
     * @throws SeedDefinitionNotFoundException
     * @throws InvalidSeedDefinitionException
     */
    public function parseFile(string $fileName): SeedDefinition
    {
        $absoluteFileName = GeneralUtility::getFileAbsFileName($fileName);
        if ($absoluteFileName === '' || !is_file($absoluteFileName)) {
            throw new SeedDefinitionNotFoundException(
                sprintf('The seed definition "%s" does not exist.', $fileName),
                1787072801,
            );
        }
        if (!is_readable($absoluteFileName)) {
            throw new SeedDefinitionNotFoundException(
                sprintf('The seed definition "%s" could not be read.', $fileName),
                1787072802,
            );
        }

        $loader = new YamlFileLoader(new ThrowOnErrorLogger($fileName));
        try {
            $content = $loader->load($absoluteFileName, YamlFileLoader::PROCESS_IMPORTS);
        } catch (YamlFileLoadingException|YamlParseException $exception) {
            throw new InvalidSeedDefinitionException(
                sprintf('The seed definition "%s" is not readable YAML: %s', $fileName, $exception->getMessage()),
                1787072803,
                $exception,
            );
        }

        return $this->parse($content, $fileName, dirname($absoluteFileName));
    }

    /**
     * @param mixed $definition The decoded YAML.
     * @param string $source Names the definition in every message. A file name
     *        where there is one.
     * @param string $basePath Absolute directory every relative resource path
     *        of the definition resolves against, see
     *        {@see SeedDefinition::$basePath}.
     * @throws InvalidSeedDefinitionException
     */
    public function parse(mixed $definition, string $source = 'seed definition', string $basePath = ''): SeedDefinition
    {
        if (!is_array($definition)) {
            throw new InvalidSeedDefinitionException(
                sprintf('The seed definition "%s" is not a map.', $source),
                1787072810,
            );
        }

        foreach (array_keys($definition) as $key) {
            if (!in_array($key, self::SET_KEYS, true)) {
                throw new InvalidSeedDefinitionException(
                    sprintf(
                        'The seed definition "%s" declares the unknown key "%s". Known keys are: %s.',
                        $source,
                        (string)$key,
                        implode(', ', self::SET_KEYS),
                    ),
                    1787072814,
                );
            }
        }

        $identifier = $definition[self::IDENTIFIER] ?? null;
        if (!is_string($identifier) || $identifier === '') {
            throw new InvalidSeedDefinitionException(
                sprintf('The seed definition "%s" has no "identifier".', $source),
                1787072811,
            );
        }

        $title = $definition[self::TITLE] ?? null;
        if (!is_string($title) || $title === '') {
            throw new InvalidSeedDefinitionException(
                sprintf('The seed definition "%s" has no "title".', $source),
                1787072812,
            );
        }

        // "description:" with nothing behind it decodes to null, which the null
        // coalescing operator treats exactly like an absent key.
        $description = $definition[self::DESCRIPTION] ?? '';
        if (!is_string($description)) {
            throw new InvalidSeedDefinitionException(
                sprintf('The "description" of the seed definition "%s" is not a string.', $source),
                1787072813,
            );
        }

        return new SeedDefinition(
            identifier: $identifier,
            title: $title,
            description: $description,
            basePath: rtrim($basePath, '/'),
            scenarios: $this->parseScenarios($definition[self::SCENARIOS] ?? null, $source),
            files: $this->parseFiles($definition[self::FILES] ?? [], $source),
            sites: $this->parseSites($definition[self::SITES] ?? [], $source),
        );
    }

    /**
     * The scenario files of the set, in declared order.
     *
     * Required and non-empty: a set that names no scenario writes no record,
     * and a descriptor that says so by omission is indistinguishable from one
     * that misspelled the key.
     *
     * @return list<string>
     */
    private function parseScenarios(mixed $scenarios, string $source): array
    {
        if (!$this->isList($scenarios) || $scenarios === []) {
            throw new InvalidSeedDefinitionException(
                sprintf(
                    'The seed definition "%s" declares no "scenarios". It is a non-empty list of the scenario'
                    . ' files the set is written from, in the order they are applied.',
                    $source,
                ),
                1787256301,
            );
        }

        $parsed = [];
        foreach ($scenarios as $scenario) {
            if (!is_string($scenario) || trim($scenario) === '') {
                throw new InvalidSeedDefinitionException(
                    sprintf('A scenario of the seed definition "%s" is not a path.', $source),
                    1787256302,
                );
            }
            $parsed[] = $scenario;
        }

        return $parsed;
    }

    /**
     * @return list<SeedFile>
     */
    private function parseFiles(mixed $files, string $source): array
    {
        if (!$this->isList($files)) {
            throw new InvalidSeedDefinitionException(
                sprintf('The "files" of the seed definition "%s" are not a list.', $source),
                1787072820,
            );
        }

        $parsed = [];
        $seen = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                throw new InvalidSeedDefinitionException(
                    sprintf('A file of the seed definition "%s" is not a map.', $source),
                    1787072821,
                );
            }
            foreach (array_keys($file) as $key) {
                if (!in_array($key, self::FILE_KEYS, true)) {
                    throw new InvalidSeedDefinitionException(
                        sprintf(
                            'A file of the seed definition "%s" declares the unknown key "%s". Known keys are: %s.',
                            $source,
                            (string)$key,
                            implode(', ', self::FILE_KEYS),
                        ),
                        1787256303,
                    );
                }
            }

            $identifier = $file[self::IDENTIFIER] ?? null;
            if (!is_string($identifier) || $identifier === '') {
                throw new InvalidSeedDefinitionException(
                    sprintf('A file of the seed definition "%s" has no "identifier".', $source),
                    1787072822,
                );
            }
            if (isset($seen[$identifier])) {
                throw new InvalidSeedDefinitionException(
                    sprintf('The file identifier "%s" is used more than once in "%s".', $identifier, $source),
                    1787072823,
                );
            }
            $seen[$identifier] = true;

            $sourcePath = $file[self::SOURCE] ?? null;
            if (!is_string($sourcePath) || $sourcePath === '') {
                throw new InvalidSeedDefinitionException(
                    sprintf('The file "%s" in "%s" has no "source".', $identifier, $source),
                    1787072824,
                );
            }

            $folder = $file[self::FOLDER] ?? '/';
            $name = $file[self::NAME] ?? null;
            $storage = $file[self::STORAGE] ?? null;

            $parsed[] = new SeedFile(
                $identifier,
                $sourcePath,
                is_string($folder) ? $folder : '/',
                is_string($name) ? $name : null,
                is_int($storage) ? $storage : null,
            );
        }

        return $parsed;
    }

    /**
     * The site configurations of the set.
     *
     * `rootPage` is the **uid** of the page that becomes the site root. A
     * scenario record carries no symbolic name, so the declared `id` is its
     * stable handle - and that id is the uid the record is written with. Only
     * the shape is checked here; that the uid is a page the set actually
     * declares can be known once the scenario is composed, and is checked
     * there.
     *
     * @return list<SeedSiteConfiguration>
     */
    private function parseSites(mixed $sites, string $source): array
    {
        if (!$this->isList($sites)) {
            throw new InvalidSeedDefinitionException(
                sprintf('The "sites" of the seed definition "%s" are not a list.', $source),
                1787072816,
            );
        }

        $parsed = [];
        $declared = [];
        foreach ($sites as $site) {
            if (!is_array($site)) {
                throw new InvalidSeedDefinitionException(
                    sprintf('A site of the seed definition "%s" is not a map.', $source),
                    1787072870,
                );
            }
            foreach (array_keys($site) as $key) {
                if (!in_array($key, self::SITE_KEYS, true)) {
                    throw new InvalidSeedDefinitionException(
                        sprintf(
                            'A site of the seed definition "%s" declares the unknown key "%s". Known keys are: %s.',
                            $source,
                            (string)$key,
                            implode(', ', self::SITE_KEYS),
                        ),
                        1787072878,
                    );
                }
            }

            $identifier = $site[self::IDENTIFIER] ?? null;
            if (!is_string($identifier) || $identifier === '') {
                throw new InvalidSeedDefinitionException(
                    sprintf('A site of the seed definition "%s" has no "identifier".', $source),
                    1787072871,
                );
            }
            if (preg_match(self::SITE_IDENTIFIER_PATTERN, $identifier) !== 1) {
                throw new InvalidSeedDefinitionException(
                    sprintf(
                        'The site identifier "%s" in the seed definition "%s" is not usable. It becomes a directory name below "config/sites/", so it may contain letters, digits, dashes and underscores only, and has to start with a letter or a digit.',
                        $identifier,
                        $source,
                    ),
                    1787072872,
                );
            }
            if (isset($declared[$identifier])) {
                throw new InvalidSeedDefinitionException(
                    sprintf('The site identifier "%s" is used more than once in "%s".', $identifier, $source),
                    1787072873,
                );
            }
            $declared[$identifier] = true;

            $rootPage = $site[self::ROOT_PAGE] ?? null;
            if (!is_int($rootPage) || $rootPage < 1) {
                throw new InvalidSeedDefinitionException(
                    sprintf(
                        'The site "%s" in "%s" declares no usable "rootPage". It is the uid a scenario entity'
                        . ' declares as its "id" for the page that becomes the site root.',
                        $identifier,
                        $source,
                    ),
                    1787072874,
                );
            }

            $template = $site[self::TEMPLATE] ?? null;
            if ($template !== null && (!is_string($template) || $template === '')) {
                throw new InvalidSeedDefinitionException(
                    sprintf('The "template" of the site "%s" in "%s" is not a path.', $identifier, $source),
                    1787072877,
                );
            }

            $base = $site[self::BASE] ?? null;
            if ($base !== null && !is_string($base)) {
                throw new InvalidSeedDefinitionException(
                    sprintf('The "base" of the site "%s" in "%s" is not a string.', $identifier, $source),
                    1787072879,
                );
            }

            $parsed[] = new SeedSiteConfiguration(
                $identifier,
                $rootPage,
                $template ?? 'Sites/' . $identifier,
                $base,
            );
        }

        return $parsed;
    }

    /**
     * @phpstan-assert-if-true list<mixed> $value
     */
    private function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }
}
