<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\Scenario;

use SBUERK\Seeder\Seeding\Definition\SeedDefinition;
use SBUERK\Seeder\Seeding\Exception\InvalidSeedDefinitionException;
use SBUERK\Seeder\Seeding\Exception\SeedDefinitionNotFoundException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Composes the scenario files a seed set declares into the single scenario the
 * import is written from.
 *
 * **One factory, not one per file.** {@see DataHandlerFactory} hands out
 * dynamic uids from 10000 upwards per entity name, so a factory per file would
 * suggest the same uid twice and the second insert would fail as a duplicate
 * primary key - a `SQL error` line in `DataHandler::$errorLog`, naming neither
 * file. Composed into one scenario, the same collision is caught by
 * `addSuggestedId()` with exception 1568146788 naming the identifier, before
 * anything is written.
 *
 * The merge is defined per key, because the two keys of the format mean
 * opposite things:
 *
 * | Key              | Rule                                                         |
 * |------------------|--------------------------------------------------------------|
 * | `entitySettings` | merged recursively, a later file wins a conflicting value     |
 * | `entities`       | appended per entity name, in the order the files are declared |
 * | `__variables`    | ignored - it only holds YAML anchors, which never cross files |
 *
 * `entitySettings` describes *how* a table is written and is naturally
 * overridden; `entities` are the records themselves, and a later file adds to
 * them rather than replacing them.
 *
 * This is a stateless service.
 *
 * @internal Part of the seeding implementation, not public API.
 */
final readonly class ScenarioComposer
{
    private const ENTITY_SETTINGS = 'entitySettings';
    private const ENTITIES = 'entities';

    /**
     * `__variables` exists so a scenario file can declare YAML anchors in one
     * place. Anchors are resolved by the parser and never cross a file, so
     * whatever is left of the key after parsing is inert - it is accepted and
     * dropped rather than rejected, because every TYPO3 Core scenario carries
     * one.
     */
    private const VARIABLES = '__variables';

    private const SCENARIO_KEYS = [
        self::ENTITY_SETTINGS,
        self::ENTITIES,
        self::VARIABLES,
    ];

    /**
     * @throws SeedDefinitionNotFoundException
     * @throws InvalidSeedDefinitionException
     * @throws \LogicException What {@see DataHandlerFactory} raises for a
     *         scenario it cannot build - an entity item without `self`, an id
     *         declared twice, a version variant on something that has none. It
     *         is not wrapped: the codes are upstream's and stay traceable to
     *         the class that raised them.
     */
    public function compose(SeedDefinition $definition, int $rootPageId = 0): DataHandlerFactory
    {
        return new DataHandlerFactory($this->composeSettings($definition, $rootPageId));
    }

    /**
     * The merged scenario, before it is handed to the factory.
     *
     * Separate from {@see self::compose()} so the composition can be asserted
     * without a factory in the way, and so a caller that wants to look at what
     * a set declares does not have to build one.
     *
     * @return array<string, mixed>
     * @throws SeedDefinitionNotFoundException
     * @throws InvalidSeedDefinitionException
     */
    public function composeSettings(SeedDefinition $definition, int $rootPageId = 0): array
    {
        /** @var array<string, mixed> $entitySettings */
        $entitySettings = [];
        /** @var array<string, list<array<string, mixed>>> $entities */
        $entities = [];

        foreach ($definition->scenarios as $scenario) {
            $settings = $this->readScenario($definition, $scenario);

            $declaredSettings = $settings[self::ENTITY_SETTINGS] ?? [];
            if ($declaredSettings !== []) {
                ArrayUtility::mergeRecursiveWithOverrule($entitySettings, $declaredSettings);
            }

            foreach ($settings[self::ENTITIES] ?? [] as $entityName => $items) {
                foreach ($items as $item) {
                    $entities[(string)$entityName][] = $item;
                }
            }
        }

        if ($rootPageId > 0) {
            $entities = $this->placeBelowRootPage($entities, $rootPageId);
        }

        return [
            self::ENTITY_SETTINGS => $entitySettings,
            self::ENTITIES => $entities,
        ];
    }

    /**
     * Reads and validates one scenario file.
     *
     * @return array{entitySettings?: array<string, mixed>, entities?: array<string, list<array<string, mixed>>>}
     * @throws SeedDefinitionNotFoundException
     * @throws InvalidSeedDefinitionException
     */
    private function readScenario(SeedDefinition $definition, string $scenario): array
    {
        $fileName = $this->resolvePath($definition, $scenario);
        if ($fileName === '' || !is_file($fileName)) {
            throw new SeedDefinitionNotFoundException(
                sprintf(
                    'The scenario "%s" of the seed set "%s" does not exist. It was looked for at "%s".',
                    $scenario,
                    $definition->identifier,
                    $fileName === '' ? $scenario : $fileName,
                ),
                1787256401,
            );
        }
        if (!is_readable($fileName)) {
            throw new SeedDefinitionNotFoundException(
                sprintf(
                    'The scenario "%s" of the seed set "%s" could not be read.',
                    $scenario,
                    $definition->identifier,
                ),
                1787256402,
            );
        }

        try {
            $settings = Yaml::parseFile($fileName);
        } catch (ParseException $exception) {
            throw new InvalidSeedDefinitionException(
                sprintf(
                    'The scenario "%s" of the seed set "%s" is not readable YAML: %s',
                    $scenario,
                    $definition->identifier,
                    $exception->getMessage(),
                ),
                1787256403,
                $exception,
            );
        }

        if (!is_array($settings)) {
            throw new InvalidSeedDefinitionException(
                sprintf('The scenario "%s" of the seed set "%s" is not a map.', $scenario, $definition->identifier),
                1787256404,
            );
        }

        foreach (array_keys($settings) as $key) {
            if (!in_array($key, self::SCENARIO_KEYS, true)) {
                throw new InvalidSeedDefinitionException(
                    sprintf(
                        'The scenario "%s" of the seed set "%s" declares the unknown key "%s". Known keys are: %s.',
                        $scenario,
                        $definition->identifier,
                        (string)$key,
                        implode(', ', self::SCENARIO_KEYS),
                    ),
                    1787256405,
                );
            }
        }

        $entitySettings = $settings[self::ENTITY_SETTINGS] ?? [];
        if (!is_array($entitySettings)) {
            throw new InvalidSeedDefinitionException(
                sprintf(
                    'The "entitySettings" of the scenario "%s" of the seed set "%s" are not a map.',
                    $scenario,
                    $definition->identifier,
                ),
                1787256406,
            );
        }

        $entities = $settings[self::ENTITIES] ?? [];
        if (!is_array($entities)) {
            throw new InvalidSeedDefinitionException(
                sprintf(
                    'The "entities" of the scenario "%s" of the seed set "%s" are not a map.',
                    $scenario,
                    $definition->identifier,
                ),
                1787256407,
            );
        }

        $result = [];
        if ($entitySettings !== []) {
            /** @var array<string, mixed> $entitySettings */
            $result[self::ENTITY_SETTINGS] = $entitySettings;
        }
        if ($entities !== []) {
            $result[self::ENTITIES] = $this->assertEntities($entities, $scenario, $definition);
        }

        return $result;
    }

    /**
     * The factory reads `entities` as a map of entity name to a list of item
     * maps and would raise a `TypeError` on anything else. A message naming the
     * file and the entity is worth the walk.
     *
     * @param array<array-key, mixed> $entities
     * @return array<string, list<array<string, mixed>>>
     * @throws InvalidSeedDefinitionException
     */
    private function assertEntities(array $entities, string $scenario, SeedDefinition $definition): array
    {
        $asserted = [];
        foreach ($entities as $entityName => $items) {
            if (!is_array($items) || !array_is_list($items)) {
                throw new InvalidSeedDefinitionException(
                    sprintf(
                        'The entity "%s" of the scenario "%s" of the seed set "%s" is not a list of items.',
                        (string)$entityName,
                        $scenario,
                        $definition->identifier,
                    ),
                    1787256408,
                );
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new InvalidSeedDefinitionException(
                        sprintf(
                            'An item of the entity "%s" of the scenario "%s" of the seed set "%s" is not a map.',
                            (string)$entityName,
                            $scenario,
                            $definition->identifier,
                        ),
                        1787256409,
                    );
                }
                /** @var array<string, mixed> $item */
                $asserted[(string)$entityName][] = $item;
            }
        }

        return $asserted;
    }

    /**
     * Writes the set below a page other than the page tree root.
     *
     * The `pid` of a **top level** item is set to the root page, unless the item
     * declares a non-zero one of its own - a scenario that names a specific page
     * means that page. Nested items, language variants and version variants are
     * untouched: they take their `pid` from their node or from their ancestor,
     * and moving them would take them off the tree they were declared in.
     *
     * This happens on the settings rather than on the built data map, and that
     * is not a detail. `DataHandlerFactory` exposes its maps read-only, so a
     * rewrite afterwards would mean changing the port; and overriding
     * `entitySettings.*.defaultValues.pid` does not work either, because
     * `buildEntityConfigurations()` iterates the *declared* entities and skips
     * `*`, so an entity that is not listed in `entitySettings` never sees the
     * wildcard defaults at all.
     *
     * @param array<string, list<array<string, mixed>>> $entities
     * @return array<string, list<array<string, mixed>>>
     */
    private function placeBelowRootPage(array $entities, int $rootPageId): array
    {
        foreach ($entities as $entityName => $items) {
            foreach ($items as $index => $item) {
                $sourceProperty = isset($item['version']) ? 'version' : 'self';
                if (!isset($item[$sourceProperty]) || !is_array($item[$sourceProperty])) {
                    continue;
                }
                if ((int)($item[$sourceProperty]['pid'] ?? 0) !== 0) {
                    continue;
                }
                $item[$sourceProperty]['pid'] = $rootPageId;
                $entities[$entityName][$index] = $item;
            }
        }

        return $entities;
    }

    /**
     * `EXT:` through the core, absolute as it is, relative against the directory
     * holding the set - the same three forms a file `source` and a site
     * `template` accept.
     *
     * An absolute path is deliberately **not** sent through
     * `GeneralUtility::getFileAbsFileName()`, which returns an empty string for
     * anything outside the project path and would turn "a set below `vendor/`"
     * into "the scenario does not exist".
     */
    private function resolvePath(SeedDefinition $definition, string $scenario): string
    {
        if (PathUtility::isExtensionPath($scenario)) {
            return GeneralUtility::getFileAbsFileName($scenario);
        }
        if (PathUtility::isAbsolutePath($scenario)) {
            return $scenario;
        }
        if ($definition->basePath === '') {
            return '';
        }

        return $definition->basePath . '/' . ltrim($scenario, '/');
    }
}
