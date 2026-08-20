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
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writes the maps a `DataHandlerFactory` produced through `DataHandler`, one
 * pass per workspace, substituting `NEW` identifiers with the uids the
 * previous pass produced.
 *
 * Ported from `typo3/testing-framework` 9.6.1, class
 * `TYPO3\TestingFramework\Core\Functional\Framework\DataHandling\Scenario\DataHandlerWriter`,
 * for the reason given on `DataHandlerFactory`. The behaviour is identical and
 * `UpstreamConformanceTest` keeps it that way.
 *
 * Note what this class does **not** do, because the seeding pipeline has to add
 * it: it never sets `DataHandler::$isImporting`, it never checks that the
 * backend user is an admin — without which `suggestedInsertUids` is silently
 * ignored — and it does not throw on a failed write, it collects `errorLog`
 * into `getErrors()` and returns.
 *
 * This is a writer holding the state of one run, not a service.
 *
 * @internal This is part of the seeding implementation of this extension and
 *           not public API.
 */
#[Exclude]
final class DataHandlerWriter
{
    /**
     * @var list<string>
     */
    private array $errors = [];

    public function __construct(
        private readonly DataHandler $dataHandler,
        private readonly BackendUserAuthentication $backendUser,
    ) {}

    public static function withBackendUser(BackendUserAuthentication $backendUser): self
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        if (isset($backendUser->uc['copyLevels']) && property_exists($dataHandler, 'copyTree')) {
            $dataHandler->copyTree = $backendUser->uc['copyLevels'];
        }
        return new self($dataHandler, $backendUser);
    }

    public function invokeFactory(DataHandlerFactory $factory): void
    {
        $this->dataHandler->suggestedInsertUids = $factory->getSuggestedIds();
        foreach ($factory->getDataMapPerWorkspace() as $workspaceId => $dataMap) {
            $dataMap = $this->updateDataMap($dataMap);
            $backendUser = clone $this->backendUser;
            $backendUser->workspace = $workspaceId;
            $this->dataHandler->start($dataMap, [], $backendUser);
            $this->dataHandler->process_datamap();
            $this->errors = array_merge($this->errors, $this->errorLog());
        }
        foreach ($factory->getCommandMapPerWorkspace() as $workspaceId => $commandMap) {
            $commandMap = $this->updateCommandMap($commandMap);
            $backendUser = clone $this->backendUser;
            $backendUser->workspace = $workspaceId;
            $this->dataHandler->start([], $commandMap, $backendUser);
            $this->dataHandler->process_cmdmap();
            $this->errors = array_merge($this->errors, $this->errorLog());
        }
    }

    /**
     * `DataHandler::$errorLog` is declared as a plain `array`. It only ever
     * holds the rendered message strings, which this narrows down to so the
     * accumulated `$errors` keeps a usable type.
     *
     * @return list<string>
     */
    private function errorLog(): array
    {
        /** @var list<string> $errorLog */
        $errorLog = $this->dataHandler->errorLog;
        return $errorLog;
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $dataMap
     * @return array<string, array<int|string, array<string, mixed>>>
     */
    private function updateDataMap(array $dataMap): array
    {
        $updatedTableDataMap = [];
        foreach ($dataMap as $tableName => $tableDataMap) {
            foreach ($tableDataMap as $key => $values) {
                $key = $this->dataHandler->substNEWwithIDs[$key] ?? $key;
                $values = array_map(
                    function ($value) {
                        if (!is_string($value)) {
                            return $value;
                        }
                        if (str_starts_with($value, 'NEW')) {
                            return $this->dataHandler->substNEWwithIDs[$value] ?? $value;
                        }
                        if (str_starts_with($value, '-NEW')) {
                            return $this->dataHandler->substNEWwithIDs[substr($value, 1)] ?? $value;
                        }
                        return $value;
                    },
                    $values
                );
                if ((string)$key === (string)(int)$key) {
                    unset($values['pid']);
                }
                $updatedTableDataMap[$tableName][$key] = $values;
            }
        }
        return $updatedTableDataMap;
    }

    /**
     * @param array<string, array<string, array<string, mixed>>> $commandMap
     * @return array<string, array<int|string, array<string, mixed>>>
     */
    private function updateCommandMap(array $commandMap): array
    {
        $updatedTableCommandMap = [];
        foreach ($commandMap as $tableName => $tableDataMap) {
            foreach ($tableDataMap as $key => $values) {
                $key = $this->dataHandler->substNEWwithIDs[$key] ?? $key;
                $values = array_map(
                    function ($value) {
                        if (!is_string($value)) {
                            return $value;
                        }
                        if (str_starts_with($value, 'NEW')) {
                            return $this->dataHandler->substNEWwithIDs[$value] ?? $value;
                        }
                        if (str_starts_with($value, '-NEW')) {
                            return $this->dataHandler->substNEWwithIDs[substr($value, 1)] ?? $value;
                        }
                        return $value;
                    },
                    $values
                );
                $updatedTableCommandMap[$tableName][$key] = $values;
            }
        }
        return $updatedTableCommandMap;
    }
}
