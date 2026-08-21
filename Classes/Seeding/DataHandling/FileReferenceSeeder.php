<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Seeding\DataHandling;

use SBUERK\DataFactory\Seeding\Definition\SeedDefinition;
use SBUERK\DataFactory\Seeding\Exception\SeedingFailedException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Attaches the files of a seed set to the records that were just written, in a
 * pass of its own.
 *
 * **Why a second pass.** A `sys_file_reference` carries the record it belongs
 * to in `uid_foreign`, and that is a plain integer column rather than a
 * relation `DataHandler` resolves: a `NEW…` placeholder written there stays a
 * string, is read as `0`, and the reference silently belongs to record 0 - with
 * an empty error log. The records therefore have to exist before their
 * references can be described at all. The `sys_file` uids have the same
 * problem from the other side: they are assigned by the FAL indexer while the
 * file is placed, so they cannot be part of the scenario either.
 *
 * **Two things go into the data map per relation**, and the second is the one
 * that is easy to leave out:
 *
 * - the `sys_file_reference` row, with `uid_local`, `uid_foreign`,
 *   `tablenames`, `fieldname` and `pid`;
 * - **the field of the parent**, as the comma separated list of the reference
 *   placeholders. Without it `DataHandler` sees no relation to resolve,
 *   `TYPO3\CMS\Core\Database\RelationHandler::writeForeignField()` never runs,
 *   and every seeded reference keeps a `sorting_foreign` of `0`.
 *
 * What that costs is invisible, which is why it is worth a paragraph:
 * `FileRepository::findByRelation()` selects by
 * `uid_foreign`/`tablenames`/`fieldname` and never reads the counter column,
 * so the images appear either way. It *orders* by `sorting_foreign`, so all
 * that is lost is the order of a multi file relation - to whatever the
 * database feels like returning. The functional test asserts the column for
 * that reason.
 *
 * This is a stateless service.
 *
 * @internal Part of the seeding implementation, not public API.
 */
final readonly class FileReferenceSeeder
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return list<int> The `sys_file_reference` uid of every declared
     *         reference, in declared order.
     * @throws SeedingFailedException
     */
    public function seed(
        SeedDefinition $definition,
        ScenarioSeedResult $seedResult,
        BackendUserAuthentication $backendUser,
    ): array {
        if ($definition->references === []) {
            return [];
        }

        $dataMap = [];
        /** @var array<string, list<string>> $perRelation */
        $perRelation = [];
        /** @var list<string> $placeholders */
        $placeholders = [];

        foreach ($definition->references as $index => $reference) {
            $fileUid = $seedResult->fileUids[$reference->file] ?? null;
            if ($fileUid === null) {
                // Unreachable through the parser, which refuses a reference to
                // a file the set does not declare. Reachable through
                // `SeedDefinition` built by hand, and a reference pointing at
                // no file would otherwise be written with `uid_local` 0.
                throw new SeedingFailedException(
                    sprintf(
                        'Seeding "%s" failed: the file reference on %s:%d names the file "%s", which was not'
                        . ' seeded.',
                        $definition->identifier,
                        $reference->table,
                        $reference->uid,
                        $reference->file,
                    ),
                    1787256501,
                );
            }

            $parentUid = $seedResult->writtenUid($reference->table, $reference->uid);
            if ($parentUid === null) {
                throw new SeedingFailedException(
                    sprintf(
                        'Seeding "%s" failed: the file reference to "%s" names the record %s:%d, which the'
                        . ' scenarios of the set do not declare. A reference names a record by the uid its'
                        . ' scenario entity declares as "id".',
                        $definition->identifier,
                        $reference->file,
                        $reference->table,
                        $reference->uid,
                    ),
                    1787256502,
                );
            }

            // The placeholder carries no underscore: `processRemapStack()`
            // reads a relation value containing one as the `<table>_<uid>`
            // form and splits it there, so "NEWsys_file_reference_1" would be
            // taken apart into a table "NEWsys_file_reference" and an id "1",
            // neither of which resolves - and the relation would be written
            // empty, with an empty error log.
            $placeholder = 'NEWsysfilereference-' . ($index + 1);
            $placeholders[] = $placeholder;

            // The declared fields first and the structural ones on top, so a
            // definition cannot detach a reference from the record it declares
            // it on by naming `uid_foreign` itself.
            $dataMap['sys_file_reference'][$placeholder] = array_merge($reference->values, [
                'uid_local' => $fileUid,
                'uid_foreign' => $parentUid,
                'tablenames' => $reference->table,
                'fieldname' => $reference->field,
                'pid' => $this->resolvePid($reference->table, $parentUid),
            ]);
            $perRelation[$reference->table . ':' . $parentUid . ':' . $reference->field][] = $placeholder;
        }

        foreach ($perRelation as $relation => $relationPlaceholders) {
            [$table, $uid, $field] = explode(':', $relation, 3);
            // An update of an existing record - the data map is keyed by the
            // uid rather than by a placeholder - carrying nothing but the
            // relation field, which is what makes DataHandler resolve the
            // relation and number it.
            $dataMap[$table][$uid][$field] = implode(',', $relationPlaceholders);
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        // The same flag as the record pass, and here it is not about the site
        // configuration at all: `FilePermissionAspect` nulls the whole field
        // array of a `sys_file_reference` whose file sits in a folder the
        // backend user has no file mount for, unless the run declares itself an
        // import. An admin passes that check today, so this is a guard rather
        // than a fix - but the passes belong to one import, and saying so only
        // in one of them is an inconsistency waiting to be found the hard way.
        $dataHandler->isImporting = true;
        $dataHandler->start($dataMap, [], $backendUser);
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== []) {
            throw new SeedingFailedException(
                sprintf(
                    'Seeding the file references of "%s" failed: %s',
                    $definition->identifier,
                    implode(' | ', array_values(array_unique($dataHandler->errorLog))),
                ),
                1787256503,
            );
        }

        $uids = [];
        foreach ($placeholders as $placeholder) {
            $uids[] = (int)($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
        }

        return $uids;
    }

    /**
     * The page a reference lives on.
     *
     * An inline child belongs to the page its parent is on, and for a parent
     * that *is* a page that means the page itself - which is the convention
     * TYPO3 follows for a page's own file fields, and the reason this is not
     * simply the parent's `pid`.
     *
     * Reading is what a `QueryBuilder` is for here; the writing goes through
     * `DataHandler` further up.
     */
    private function resolvePid(string $table, int $parentUid): int
    {
        if ($table === 'pages') {
            return $parentUid;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $pid = $queryBuilder
            ->select('pid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($parentUid)))
            ->executeQuery()
            ->fetchOne();

        return is_numeric($pid) ? (int)$pid : 0;
    }
}
