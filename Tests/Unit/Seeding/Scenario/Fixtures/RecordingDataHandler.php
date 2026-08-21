<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Unit\Seeding\Scenario\Fixtures;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * A `DataHandler` that writes nothing and remembers everything.
 *
 * `DataHandlerWriter` substitutes the `NEW…` identifiers of one round with the
 * uids the rounds before it produced, which is string handling and needs no
 * database — but it reads those uids from `DataHandler::$substNEWwithIDs` and
 * hands the result to `DataHandler::start()`, so testing it needs something in
 * that position. This records what it is given and fills `substNEWwithIDs` the
 * way a real run does: every `NEW…` key of a data map becomes a uid.
 *
 * The constructor is overridden and does not call its parent, so none of the
 * injected services of the real class is needed. That is safe exactly as long
 * as nothing but the four members below is touched — a test reaching further
 * fails on an uninitialised property rather than silently working.
 *
 * `start()` takes its parameters after the two maps as a variadic: TYPO3 v14
 * added a `CorrelationId` argument to the signature that v13 does not have, and
 * a variadic is compatible with both.
 */
final class RecordingDataHandler extends DataHandler
{
    /**
     * @var list<array<string, array<int|string, array<string, mixed>>>>
     */
    public array $recordedDataMaps = [];

    /**
     * @var list<array<string, array<int|string, array<string, mixed>>>>
     */
    public array $recordedCommandMaps = [];

    private int $nextUid = 1;

    public function __construct() {}

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $dataMap
     * @param array<string, array<int|string, array<string, mixed>>> $commandMap
     */
    public function start(array $dataMap, array $commandMap, mixed ...$rest): void
    {
        if ($dataMap !== []) {
            $this->recordedDataMaps[] = $dataMap;
        }
        if ($commandMap !== []) {
            $this->recordedCommandMaps[] = $commandMap;
        }
        $this->datamap = $dataMap;
    }

    public function process_datamap(): void
    {
        foreach ($this->datamap as $tableDataMap) {
            foreach (array_keys($tableDataMap) as $key) {
                if (!is_string($key) || !str_starts_with($key, 'NEW')) {
                    continue;
                }
                $this->substNEWwithIDs[$key] = $this->nextUid++;
            }
        }
    }

    public function process_cmdmap(): void {}
}
