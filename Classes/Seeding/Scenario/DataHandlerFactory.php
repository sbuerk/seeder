<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\Scenario;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Yaml\Yaml;

/**
 * Factory for DataHandler information parsed from a structured array
 * (or more specifically a scenario definition written in YAML).
 *
 * Ported from `typo3/testing-framework` 9.6.1, class
 * `TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\Scenario\DataHandlerFactory`.
 * The behaviour is deliberately identical — `UpstreamConformanceTest` asserts
 * that this class and the upstream one produce the same maps for the same
 * definition, so a divergence is a test failure rather than a surprise.
 *
 * It is ported rather than depended on because `typo3/testing-framework`
 * requires `phpunit/phpunit`, and this extension seeds production
 * installations. The class itself needs nothing from that package: it reads
 * YAML and returns arrays, and touches no TYPO3 API at all.
 *
 * This is a builder, not a service. It carries the state of one parse run and
 * is never fetched from the container.
 *
 * @internal This is part of the seeding implementation of this extension and
 *           not public API.
 */
#[Exclude]
final class DataHandlerFactory
{
    private const DYNAMIC_ID = 10000;

    /**
     * @var array<string, mixed>
     */
    private array $settings;

    /**
     * @var array<string, EntityConfiguration>
     */
    private array $entityConfigurations = [];

    /**
     * @var array<int, array<string, array<string, array<string, mixed>>>>
     */
    private array $dataMapPerWorkspace = [];

    /**
     * @var array<int, array<string, array<string, array<string, mixed>>>>
     */
    private array $commandMapPerWorkspace = [];

    /**
     * @var array<string, true>
     */
    private array $suggestedIds = [];

    /**
     * @var array<string, int>
     */
    private array $dynamicIdsPerEntity = [];

    /**
     * @var array<string, list<int>>
     */
    private array $staticIdsPerEntity = [];

    public static function fromYamlFile(string $yamlFile): self
    {
        return new self(Yaml::parseFile($yamlFile));
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $this->buildEntityConfigurations($settings['entitySettings'] ?? []);
        $this->processEntities($this->settings['entities'] ?? []);
    }

    /**
     * @return array<int, array<string, array<string, array<string, mixed>>>>
     */
    public function getDataMapPerWorkspace(): array
    {
        return $this->dataMapPerWorkspace;
    }

    /**
     * @return array<int, array<string, array<string, array<string, mixed>>>>
     */
    public function getCommandMapPerWorkspace(): array
    {
        return $this->commandMapPerWorkspace;
    }

    /**
     * @return array<int, string>
     */
    public function getDataMapTableNames(): array
    {
        return array_unique(array_merge(
            [],
            ...array_map('array_keys', $this->dataMapPerWorkspace)
        ));
    }

    /**
     * @return array<string, true>
     */
    public function getSuggestedIds(): array
    {
        return $this->suggestedIds;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $settings
     */
    private function processEntities(
        array $settings,
        ?string $nodeId = null,
        ?string $parentId = null
    ): void {
        foreach ($settings as $entityName => $entitySettings) {
            $entityConfiguration = $this->provideEntityConfiguration($entityName);
            foreach ($entitySettings as $itemSettings) {
                $this->processEntityItem(
                    $entityConfiguration,
                    $itemSettings,
                    $nodeId,
                    $parentId
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $itemSettings
     */
    private function processEntityItem(
        EntityConfiguration $entityConfiguration,
        array $itemSettings,
        ?string $nodeId = null,
        ?string $parentId = null
    ): void {
        $values = $this->processEntityValues(
            $entityConfiguration,
            $itemSettings,
            $nodeId,
            $parentId
        );
        $workspaceId = $itemSettings['version']['workspace'] ?? 0;
        $tableName = $entityConfiguration->getTableName();
        $newId = $this->getUniqueIdForNewRecords();
        $this->setInDataMap($tableName, $newId, $values, (int)$workspaceId);
        if (isset($itemSettings['actions'])) {
            $this->setInCommandMap($tableName, $newId, $nodeId, $itemSettings['actions'], (int)$workspaceId);
        }
        foreach ($itemSettings['versionVariants'] ?? [] as $versionVariantSettings) {
            $this->processVersionVariantItem(
                $entityConfiguration,
                $versionVariantSettings,
                $newId,
                $entityConfiguration->isNode() ? $newId : $nodeId
            );
        }
        foreach ($itemSettings['languageVariants'] ?? [] as $variantItemSettings) {
            $this->processLanguageVariantItem(
                $entityConfiguration,
                $variantItemSettings,
                [$newId],
                $entityConfiguration->isNode() ? '-' . $newId : $nodeId
            );
        }
        foreach ($itemSettings['children'] ?? [] as $childItemSettings) {
            $this->processEntityItem(
                $entityConfiguration,
                $childItemSettings,
                $nodeId,
                $newId
            );
        }
        if (!empty($itemSettings['entities']) && $entityConfiguration->isNode()) {
            $this->processEntities(
                $itemSettings['entities'],
                $newId,
                $parentId
            );
        }
    }

    /**
     * @param array<string, mixed> $itemSettings
     * @param list<string> $ancestorIds
     */
    private function processLanguageVariantItem(
        EntityConfiguration $entityConfiguration,
        array $itemSettings,
        array $ancestorIds,
        ?string $nodeId = null
    ): void {
        $values = $this->processEntityValues(
            $entityConfiguration,
            $itemSettings,
            $nodeId
        );
        // Language values can be overridden by declared values
        $values = array_merge(
            $entityConfiguration->processLanguageValues($ancestorIds),
            $values
        );
        $tableName = $entityConfiguration->getTableName();
        $newId = $this->getUniqueIdForNewRecords();
        $workspaceId = $itemSettings['version']['workspace'] ?? 0;
        $this->setInDataMap($tableName, $newId, $values, (int)$workspaceId);
        if (isset($itemSettings['actions'])) {
            $this->setInCommandMap($tableName, $newId, $nodeId, $itemSettings['actions'], (int)$workspaceId);
        }
        foreach ($itemSettings['versionVariants'] ?? [] as $versionVariantSettings) {
            $this->processVersionVariantItem(
                $entityConfiguration,
                $versionVariantSettings,
                $newId,
                $nodeId
            );
        }
        foreach ($itemSettings['languageVariants'] ?? [] as $variantItemSettings) {
            $this->processLanguageVariantItem(
                $entityConfiguration,
                $variantItemSettings,
                array_merge($ancestorIds, [$newId]),
                $nodeId
            );
        }
    }

    /**
     * @param array<string, mixed> $itemSettings
     */
    private function processVersionVariantItem(
        EntityConfiguration $entityConfiguration,
        array $itemSettings,
        string $ancestorId,
        ?string $nodeId = null
    ): void {
        if (isset($itemSettings['self'])) {
            throw new \LogicException(
                sprintf(
                    'Cannot declare "self" in version variant for entity "%s"',
                    $entityConfiguration->getName()
                ),
                1574365935
            );
        }
        if (isset($itemSettings['version']['id'])) {
            throw new \LogicException(
                sprintf(
                    'Cannot assign "id" for version variant for entity "%s"',
                    $entityConfiguration->getName()
                ),
                1574365936
            );
        }
        $values = $this->processEntityValues(
            $entityConfiguration,
            $itemSettings,
            $nodeId
        );
        $tableName = $entityConfiguration->getTableName();
        // Deliberate divergence from `typo3/testing-framework` 9.6.1, which
        // reads `$values['workspace']` here - the *processed* values, after
        // `columnNames` has been applied. An entity mapping `workspace` to
        // another column therefore leaves no `workspace` key behind, the
        // expression warns about an undefined key and evaluates to workspace 0,
        // and the version variant is written over the live record it was meant
        // to version. The declared value is what every other call site uses.
        $workspaceId = (int)$itemSettings['version']['workspace'];
        $this->setInDataMap($tableName, $ancestorId, $values, $workspaceId);
        if (isset($itemSettings['actions'])) {
            $this->setInCommandMap($tableName, $ancestorId, $nodeId, $itemSettings['actions'], $workspaceId);
        }
    }

    /**
     * @param array<string, mixed> $itemSettings
     * @return array<string, mixed>
     */
    private function processEntityValues(
        EntityConfiguration $entityConfiguration,
        array $itemSettings,
        ?string $nodeId = null,
        ?string $parentId = null
    ): array {
        if (isset($itemSettings['self']) && isset($itemSettings['version'])) {
            throw new \LogicException(
                sprintf(
                    'Cannot declare both "self" and "version" for entity "%s"',
                    $entityConfiguration->getName()
                ),
                1534872399
            );
        }
        if (isset($itemSettings['version']) && empty($itemSettings['version']['workspace'])) {
            throw new \LogicException(
                sprintf(
                    'Cannot declare "version" without "workspace" for entity "%s"',
                    $entityConfiguration->getName()
                ),
                1534872400
            );
        }
        $sourceProperty = isset($itemSettings['version']) ? 'version' : 'self';
        if (empty($itemSettings[$sourceProperty]) || !is_array($itemSettings[$sourceProperty])) {
            throw new \LogicException(
                sprintf(
                    'Missing "%s" declaration for entity "%s"',
                    $sourceProperty,
                    $entityConfiguration->getName()
                ),
                1533734369
            );
        }
        $staticId = (int)($itemSettings[$sourceProperty]['id'] ?? 0);
        if ($this->hasStaticId($entityConfiguration, $staticId)) {
            throw new \LogicException(
                sprintf(
                    'Cannot assign ID "%s" multiple times',
                    $staticId
                ),
                1533734370
            );
        }
        $parentColumnName = $entityConfiguration->getParentColumnName();
        $nodeColumnName = $entityConfiguration->getNodeColumnName();
        // @todo probably dynamic assignment is a bad idea & we should just use auto incremented values...
        $incrementValue = !empty($itemSettings['version']) ? 2 : 1;
        if ($staticId > 0) {
            $suggestedId = $staticId;
            // Deliberate divergence from `typo3/testing-framework` 9.6.1, which
            // declares `$staticIdsPerEntity`, reads it in `hasStaticId()` and
            // never writes it, so the guard above is always false and its
            // exception 1533734370 cannot be raised. Registering the id here is
            // what the property is for, and it moves the refusal of a duplicate
            // `id:` from `addSuggestedId()` - which reports it as the table and
            // uid it happened to resolve to - to the entity and the id the set
            // author actually wrote down.
            $this->staticIdsPerEntity[$entityConfiguration->getName()][] = $staticId;
            $this->incrementDynamicId($entityConfiguration, $incrementValue - 1);
        } else {
            $suggestedId = $this->incrementDynamicId($entityConfiguration, $incrementValue);
        }
        $this->addSuggestedId($entityConfiguration, $suggestedId);
        $values = $entityConfiguration->processValues($itemSettings[$sourceProperty]);
        $values['uid'] = $suggestedId;
        // Assign node pointer value
        if ($nodeId !== null && !empty($nodeColumnName)) {
            $values[$nodeColumnName] = $nodeId;
        }
        // Assign parent pointer value
        if ($parentId !== null && !empty($parentColumnName)) {
            $values[$parentColumnName] = $parentId;
        }
        return $values;
    }

    /**
     * @param array<string, array<string, mixed>> $settings
     */
    private function buildEntityConfigurations(array $settings): void
    {
        $defaultSettings = $settings['*'] ?? [];
        foreach ($settings as $entityName => $entitySettings) {
            if ($entityName === '*') {
                continue;
            }
            $entityConfiguration = EntityConfiguration::fromArray(
                $entityName,
                array_merge_recursive(
                    $defaultSettings,
                    $entitySettings
                )
            );
            $this->entityConfigurations[$entityName] = $entityConfiguration;
        }
    }

    private function provideEntityConfiguration(
        string $entityName
    ): EntityConfiguration {
        if (empty($this->entityConfigurations[$entityName])) {
            $this->entityConfigurations[$entityName] = new EntityConfiguration($entityName);
        }
        return $this->entityConfigurations[$entityName];
    }

    private function addSuggestedId(
        EntityConfiguration $entityConfiguration,
        int $suggestedId
    ): void {
        $identifier = $entityConfiguration->getTableName() . ':' . $suggestedId;
        if (isset($this->suggestedIds[$identifier])) {
            throw new \LogicException(
                sprintf(
                    'Cannot redeclare identifier "%s" with "%d"',
                    $identifier,
                    $suggestedId
                ),
                1568146788
            );
        }
        $this->suggestedIds[$identifier] = true;
    }

    private function hasStaticId(
        EntityConfiguration $entityConfiguration,
        int $id
    ): bool {
        return in_array(
            $id,
            $this->staticIdsPerEntity[$entityConfiguration->getName()] ?? [],
            true
        );
    }

    private function incrementDynamicId(
        EntityConfiguration $entityConfiguration,
        int $incrementValue = 1
    ): int {
        if (!isset($this->dynamicIdsPerEntity[$entityConfiguration->getName()])) {
            $this->dynamicIdsPerEntity[$entityConfiguration->getName()] = self::DYNAMIC_ID;
        }
        $result = $this->dynamicIdsPerEntity[$entityConfiguration->getName()];
        // increment for next(!) assignment, since current process might create version or language variants
        $this->dynamicIdsPerEntity[$entityConfiguration->getName()] += $incrementValue;
        return $result;
    }

    /**
     * Adds values to data map and ensures sorting.
     * Per default DataHandler inserts records to top on according page
     * however, this factory shall insert sequentially one after another.
     */
    /**
     * @param array<string, mixed> $values
     */
    private function setInDataMap(
        string $tableName,
        string $identifier,
        array $values,
        int $workspaceId = 0
    ): void {
        if (empty($values)) {
            $this->dataMapPerWorkspace[$workspaceId][$tableName][$identifier] = $values;
            return;
        }
        $tableDataMap = $this->filterDataMapByPageId(
            $workspaceId,
            $tableName,
            $values['pid'] ?? null
        );
        $identifiers = array_keys($tableDataMap);
        $currentIndex = array_search($identifier, $identifiers);
        // current item did not have any values in data map, use last identifer
        if ($currentIndex === false && !empty($identifiers)) {
            $values['pid'] = '-' . $identifiers[count($identifiers) - 1];
            // current item does have values in data map, use previous identifier
        } elseif ($currentIndex > 0) {
            // Deliberate divergence from `typo3/testing-framework` 9.6.1, which
            // reads `$identifiers[$identifiers[$currentIndex - 1]]` here and so
            // indexes a list of identifiers by an identifier rather than by an
            // index, warning about an undefined key and writing a bare `-`.
            //
            // The branch is reachable, which is easy to get wrong: records are
            // normally keyed by a fresh `uniqid('NEW')` and so never already
            // present, but `processVersionVariantItem()` re-uses the ancestor
            // key. Reaching it needs the table to be literally `pages` (only
            // those back-references are resolved), a non-node entity, item and
            // version variant in the same non-zero workspace, an explicit `pid`
            // and two preceding siblings. `UpstreamConformanceTest` pins both
            // sides of it, and it is the one case that test excludes.
            $values['pid'] = '-' . $identifiers[$currentIndex - 1];
        }
        $this->dataMapPerWorkspace[$workspaceId][$tableName][$identifier] = $values;
    }

    /**
     * @param array<array-key, array<string, mixed>> $actionItems
     */
    private function setInCommandMap(
        string $tableName,
        string $identifier,
        ?string $nodeId,
        array $actionItems,
        int $workspaceId = 0
    ): void {
        if (empty($actionItems)) {
            return;
        }
        // @todo implement `immediate` actions -> needs to split dataMap & commandMap in logical sections
        foreach ($actionItems as $actionItem) {
            $action = $actionItem['action'] ?? null;
            $type = $actionItem['type'] ?? null;
            $target = $actionItem['target'] ?? null;
            if ($action === 'move') {
                if ($type === 'toPage' && $target !== null) {
                    $this->commandMapPerWorkspace[$workspaceId][$tableName][$identifier]['move'] = $target;
                } elseif ($type === 'toTop' && $nodeId !== null) {
                    $this->commandMapPerWorkspace[$workspaceId][$tableName][$identifier]['move'] = $nodeId;
                } elseif ($type === 'afterRecord' && $target !== null) {
                    $this->commandMapPerWorkspace[$workspaceId][$tableName][$identifier]['move'] = '-' . $target;
                }
            } elseif ($action === 'delete') {
                $this->commandMapPerWorkspace[$workspaceId][$tableName][$identifier]['delete'] = true;
            } elseif ($action === 'discard' && $workspaceId > 0) {
                // Deliberate divergence from `typo3/testing-framework` 9.6.1,
                // which emits `clearWSID` as the *command name*.
                // Nothing consumes it as a command name on either supported
                // core version, so the command is dropped with no branch and no
                // log entry and the action does nothing at all. `clearWSID` is
                // an *action* of the `version` command, handled by
                // `EXT:workspaces` (`DataHandlerHook::processCmdmap()`), which
                // is how both
                // `TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\ActionService`
                // and `WorkspaceService::flushWorkspaceRecords()` discard a
                // record.
                //
                // @todo TYPO3 v14 added a `discard` command that says what it
                //       does. It exists on neither version this branch
                //       supports, so the switch belongs to the 2.x line -
                //       core carries a `@todo` of its own to remove the
                //       `clearWSID` action once nothing uses it any more.
                $this->commandMapPerWorkspace[$workspaceId][$tableName][$identifier]['version'] = ['action' => 'clearWSID'];
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function filterDataMapByPageId(
        int $workspaceId,
        string $tableName,
        int|string|null $pageId = null
    ): array {
        if ($pageId === null) {
            return [];
        }
        return array_filter(
            $this->dataMapPerWorkspace[$workspaceId][$tableName] ?? [],
            function (array $item) use ($pageId, $workspaceId, $tableName) {
                $itemPageId = $this->resolveDataMapPageId(
                    $workspaceId,
                    $tableName,
                    $item['pid'] ?? null
                );
                return $itemPageId === $pageId;
            }
        );
    }

    /**
     * Deliberate divergence from `typo3/testing-framework` 9.6.1, which looks
     * the back reference up in `dataMapPerWorkspace[$workspaceId]['pages']`
     * whatever table it is resolving. A `-NEW…` pointer is written by
     * `setInDataMap()` against the data map of the record's own table, so on
     * every table but `pages` the lookup misses, the pointer resolves to
     * `null`, and the record drops out of `filterDataMapByPageId()`. The
     * filtered list therefore never grows past its first entry and from the
     * third record of a page onwards every one of them is chained behind the
     * first, which reverses the declared order in the backend.
     */
    private function resolveDataMapPageId(int $workspaceId, string $tableName, int|string|null $pageId = null): int|string|null
    {
        $normalizePageId = (string)$pageId;
        if ($pageId === null || $normalizePageId[0] !== '-') {
            return $pageId;
        }

        $regularPageId = substr($normalizePageId, 1);
        $resolvedPageId = $this->dataMapPerWorkspace[$workspaceId][$tableName][$regularPageId]['pid'] ?? null;
        return $this->resolveDataMapPageId($workspaceId, $tableName, $resolvedPageId);
    }

    /**
     * This function generates a unique id by using the more entropy parameter, so it can be used in DataHandler.
     */
    private function getUniqueIdForNewRecords(): string
    {
        return str_replace('.', '', uniqid('NEW', true));
    }
}
