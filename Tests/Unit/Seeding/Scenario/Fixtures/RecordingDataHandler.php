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
 * `start()` declares both maps as `mixed` and takes everything after them as a
 * variadic, which is the only signature valid on both supported core versions.
 * TYPO3 v13 declares `start(array $dataMap, array $commandMap,
 * ?BackendUserAuthentication, ?ReferenceIndexUpdater): void`, while v12.4
 * declares `start($data, $cmd, $altUserObject = null)` with no types and no
 * return type at all. Narrowing the two maps to `array` therefore satisfies v13
 * and breaks v12, where the parent accepts anything - PHP allows a child to
 * widen a parameter type, never to narrow one. The real types are stated in the
 * docblock below, which is what the analysis reads.
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
    public function start(mixed $dataMap, mixed $commandMap, mixed ...$rest): void
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
