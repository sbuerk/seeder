<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\DataHandling;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Finds the uids a seed set suggests and the installation already uses.
 *
 * ## Why this is not an emptiness check
 *
 * The command this extension is extracted from refused to import whenever a
 * page with uid 1 existed. That is wrong in both directions: a set that
 * suggests uid 200 for every page is refused although nothing of it collides,
 * and a set suggesting `tt_content:1` is imported although that content
 * element is taken - after which `DataHandler` writes it with the next free
 * uid instead and the seed silently no longer matches its own definition,
 * because a suggested uid is a suggestion and not a demand
 * (`DataHandler::insertDB()`, quoted in {@see ScenarioSeeder}).
 *
 * So the question is asked per uid and per table, which is the granularity a
 * collision has, and the answer names the records that are in the way rather
 * than reporting that "something" is.
 *
 * ## Deleted records occupy a uid
 *
 * The query runs with every restriction removed, so a soft deleted, hidden or
 * time restricted record counts as occupying its uid. It does: `uid` is the
 * primary key of the table and a row flagged `deleted = 1` is still a row, so
 * an insert with that uid fails - or, worse, does not fail and lands on
 * whatever uid was free. What a restriction set would hide here is exactly the
 * case that is hardest to explain afterwards.
 *
 * @internal Part of the seeding implementation, not public API.
 */
final readonly class UidCollisionDetector
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, true> $suggestedUids The uids of the run, keyed
     *        `<table>:<uid>` - what
     *        {@see \SBUERK\Seeder\Seeding\Scenario\DataHandlerFactory::getSuggestedIds()}
     *        returns and `DataHandler::$suggestedInsertUids` is handed. Taking
     *        the check from there rather than from the scenario file is
     *        deliberate: what is checked is then literally what will be
     *        written, including the dynamic uids the factory hands out for an
     *        entity that declares none.
     * @return list<OccupiedUid> Empty when nothing collides, which is the case
     *         a caller checks for. Ordered by table and uid, so two runs of the
     *         same set report the same list.
     */
    public function detect(array $suggestedUids): array
    {
        /** @var array<string, list<int>> $perTable */
        $perTable = [];
        foreach (array_keys($suggestedUids) as $suggestion) {
            $parts = explode(':', $suggestion, 2);
            if (count($parts) !== 2 || $parts[0] === '' || !is_numeric($parts[1])) {
                continue;
            }
            $perTable[$parts[0]][] = (int)$parts[1];
        }
        ksort($perTable, SORT_STRING);

        $occupied = [];
        foreach ($perTable as $table => $uids) {
            sort($uids, SORT_NUMERIC);
            foreach ($this->occupiedIn($table, $uids) as $record) {
                $occupied[] = $record;
            }
        }

        return $occupied;
    }

    /**
     * @param list<int> $uids
     * @return list<OccupiedUid>
     */
    private function occupiedIn(string $table, array $uids): array
    {
        if ($uids === [] || !isset($GLOBALS['TCA'][$table])) {
            // A table without TCA is not skipped to be lenient: `DataHandler`
            // refuses to write one at all, so such a set fails in the writing
            // pass with a message about the table rather than about a uid, and
            // querying a table that may not exist to say so first would trade
            // that message for a database error.
            return [];
        }

        $labelField = $this->labelFieldOf($table);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->select(...($labelField === null ? ['uid'] : ['uid', $labelField]))
            ->from($table)
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY),
                ),
            )
            ->orderBy('uid');

        $occupied = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $title = $labelField === null ? '' : ($row[$labelField] ?? '');
            $occupied[] = new OccupiedUid(
                $table,
                (int)$row['uid'],
                is_scalar($title) ? trim((string)$title) : '',
            );
        }

        return $occupied;
    }

    /**
     * The field a record of this table is named by, or `null` when there is
     * none to read.
     *
     * `ctrl.label` may name a field the table has no column for - a `label` of
     * a table whose TCA was assembled by an extension is not validated
     * anywhere - so it is only used when the TCA declares a column of that
     * name. Selecting a column that does not exist would turn a helpful
     * refusal into a database error.
     */
    private function labelFieldOf(string $table): ?string
    {
        $labelField = $GLOBALS['TCA'][$table]['ctrl']['label'] ?? null;
        if (!is_string($labelField) || $labelField === '') {
            return null;
        }
        if (!isset($GLOBALS['TCA'][$table]['columns'][$labelField])) {
            return null;
        }

        return $labelField;
    }
}
