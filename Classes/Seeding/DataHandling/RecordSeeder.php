<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\DataHandling;

use SBUERK\Seeder\Seeding\Definition\SeedDefinition;
use SBUERK\Seeder\Seeding\Definition\SeedRecord;
use SBUERK\Seeder\Seeding\Exception\InvalidSeedDefinitionException;
use SBUERK\Seeder\Seeding\Exception\SeedingFailedException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writes the records of a seed definition into the database, through
 * `DataHandler`.
 *
 * Going through `DataHandler` rather than writing rows directly is the whole
 * point of this class. It is what makes the result a TYPO3 page tree rather
 * than a set of rows that merely look like one: slugs are generated, the TCA
 * defaults and evaluations are applied, `sorting` is computed, relations are
 * resolved, the reference index is updated and the caches are flushed. A seeder
 * writing SQL has to reimplement all of that, and gets it subtly wrong.
 *
 * It takes **two** passes to do it. The records go first, and the file
 * references of those records follow once their uids are known, because
 * `sys_file_reference.uid_foreign` is a plain integer column DataHandler
 * resolves no placeholder in - see {@see self::attachFileReferences()}.
 *
 * @phpstan-import-type SeedFileReferenceRow from DataMapFactory
 *
 * @internal Part of the seeding implementation, not public API.
 */
final readonly class RecordSeeder
{
    public function __construct(
        private DataMapFactory $dataMapFactory,
        private FileSeeder $fileSeeder,
    ) {}

    /**
     * @param BackendUserAuthentication $backendUser The user the write is
     *        performed as. It has to be an admin, see below.
     * @param int $rootPageId The page the top level records are written below.
     * @return array<string, int> The uids the records were written with, keyed
     *         by their seed identifier.
     * @throws InvalidSeedDefinitionException
     * @throws SeedingFailedException
     */
    public function seed(
        SeedDefinition $definition,
        BackendUserAuthentication $backendUser,
        int $rootPageId = 0,
    ): array {
        if (!$backendUser->isAdmin()) {
            // "As a security measure this feature is available only for Admin
            // Users (for now)" - DataHandler::insertDB() evaluates a suggested
            // uid behind "$this->BE_USER->isAdmin() &&" (13.4:
            // DataHandler.php:7806, 14.3: DataHandler.php:7781) and does not
            // say a word when the check fails. A seed declaring uid 1 would
            // quietly come out with whatever uid was free, and every site
            // configuration pointing at that root page would be wrong.
            throw new SeedingFailedException(
                sprintf(
                    'Seeding "%s" requires an admin backend user: DataHandler honours a suggested uid only for'
                    . ' an admin and ignores it silently otherwise, which would write the seed with different uids'
                    . ' than it declares.',
                    $definition->identifier,
                ),
                1787075001,
            );
        }

        // Files first: a record referencing one needs its "sys_file" uid before
        // the data map can describe the reference at all.
        $fileUids = $this->fileSeeder->seed($definition);

        $map = $this->dataMapFactory->createFromDefinition($definition, $rootPageId, $fileUids);
        if ($map['dataMap'] === []) {
            throw new SeedingFailedException(
                sprintf('The seed definition "%s" contains no records.', $definition->identifier),
                1787075002,
            );
        }

        // DataHandler is stateful for the duration of one run - it accumulates
        // "substNEWwithIDs", the error log and its remap stack - so it is
        // created per run rather than injected, which is what the core itself
        // does everywhere it needs one.
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->suggestedInsertUids = $map['suggestedUids'];
        $dataHandler->start($map['dataMap'], [], $backendUser);
        $dataHandler->process_datamap();
        $this->assertNoErrors($dataHandler, $definition);

        $this->attachFileReferences($map['references'], $dataHandler->substNEWwithIDs, $backendUser, $definition);

        return $this->collectWrittenUids($definition->records, $dataHandler);
    }

    /**
     * Writes the file references of the seeded records, in a second pass.
     *
     * A reference carries the uid of the record it belongs to in `uid_foreign`,
     * and that is a plain integer column rather than a relation DataHandler
     * resolves: a `NEW…` placeholder written there stays a string, is read as
     * `0`, and the reference ends up on record 0 - with an empty error log. The
     * records therefore have to exist before their references can be described,
     * which is what makes this a second pass rather than more rows in the same
     * data map.
     *
     * Two things are written per reference, and the second is the one that is
     * easy to leave out:
     *
     * - the `sys_file_reference` row itself, with `uid_local`, `uid_foreign`,
     *   `tablenames`, `fieldname` and `pid`;
     * - **the counter field of the parent**, as the comma separated list of the
     *   reference placeholders. Without it DataHandler sees no relation to
     *   resolve, `RelationHandler::writeForeignField()` never runs, and every
     *   seeded reference keeps a `sorting_foreign` of `0`.
     *
     * What that costs is invisible, which is why it survived a long time:
     * `FileRepository::findByRelation()` selects by
     * `uid_foreign`/`tablenames`/`fieldname` and never reads the counter
     * column, so the images appear either way. It *orders* by
     * `sorting_foreign`, so all that is lost is the order of a multi file
     * relation - to whatever the database feels like returning. The functional
     * test asserts the column for that reason.
     *
     * @param list<SeedFileReferenceRow> $references
     * @param array<string, int|string> $written Placeholder to written uid,
     *        which is `DataHandler::$substNEWwithIDs` of the first pass.
     * @throws SeedingFailedException
     */
    private function attachFileReferences(
        array $references,
        array $written,
        BackendUserAuthentication $backendUser,
        SeedDefinition $definition,
    ): void {
        if ($references === []) {
            return;
        }

        $dataMap = [];
        $counter = 0;
        /** @var array<string, list<string>> $perRelation */
        $perRelation = [];

        foreach ($references as $reference) {
            $parentUid = $written[$reference['parent']] ?? null;
            if ($parentUid === null) {
                throw new SeedingFailedException(
                    sprintf(
                        'Seeding "%s" failed: the record "%s" was not written, so the file reference declared on'
                        . ' it cannot be attached.',
                        $definition->identifier,
                        $reference['parent'],
                    ),
                    1787076004,
                );
            }
            // The pid of a reference is the page of its level, which is either
            // the placeholder of a seeded page or the root page id the seed was
            // written below - the one case where there is nothing to resolve.
            $pid = $written[$reference['pid']] ?? $reference['pid'];
            // The placeholder carries no underscore, for the same reason a
            // record's does: "DataHandler::processRemapStack()" reads a relation
            // value containing one as the "<table>_<uid>" form and splits it
            // there, so "NEWsys_file_reference_1" is taken apart into a table
            // "NEWsys_file_reference" and an id "1", neither of which resolves -
            // and the relation is written empty, with an empty error log.
            // {@see SeedRecord::placeholder()} quotes the branch.
            $placeholder = 'NEWsysfilereference-' . ++$counter;
            // The declared fields first and the structural ones on top, so a
            // definition cannot detach a reference from the record declaring it
            // by naming "uid_foreign" itself. Same rule as a record's "pid".
            $dataMap['sys_file_reference'][$placeholder] = array_merge($reference['values'], [
                'uid_local' => $reference['file'],
                'uid_foreign' => (int)$parentUid,
                'tablenames' => $reference['table'],
                'fieldname' => $reference['field'],
                'pid' => (int)$pid,
            ]);
            $perRelation[$reference['table'] . ':' . $parentUid . ':' . $reference['field']][] = $placeholder;
        }

        foreach ($perRelation as $relation => $placeholders) {
            [$table, $uid, $field] = explode(':', $relation, 3);
            // An update of an existing record - the data map is keyed by the
            // uid rather than by a placeholder - carrying nothing but the
            // counter field, which is what makes DataHandler resolve the
            // relation and number it.
            $dataMap[$table][$uid][$field] = implode(',', $placeholders);
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, [], $backendUser);
        $dataHandler->process_datamap();
        $this->assertNoErrors($dataHandler, $definition);
    }

    /**
     * DataHandler reports a refused write by logging it and carrying on, so a
     * run that wrote half a page tree looks exactly like one that wrote all of
     * it. Turning the log into an exception is what makes the difference
     * visible.
     *
     * @throws SeedingFailedException
     */
    private function assertNoErrors(DataHandler $dataHandler, SeedDefinition $definition): void
    {
        if ($dataHandler->errorLog === []) {
            return;
        }

        throw new SeedingFailedException(
            sprintf(
                'Seeding "%s" failed: %s',
                $definition->identifier,
                implode(' | ', $dataHandler->errorLog),
            ),
            1787075003,
        );
    }

    /**
     * @param list<SeedRecord> $records
     * @return array<string, int>
     */
    private function collectWrittenUids(array $records, DataHandler $dataHandler): array
    {
        $uids = [];
        foreach ($records as $record) {
            $written = $dataHandler->substNEWwithIDs[$record->placeholder()] ?? null;
            if ($written !== null) {
                $uids[$record->identifier] = (int)$written;
            }
            if ($record->children !== []) {
                $uids = [...$uids, ...$this->collectWrittenUids($record->children, $dataHandler)];
            }
            foreach ($record->inline as $inlineChildren) {
                $uids = [...$uids, ...$this->collectWrittenUids($inlineChildren, $dataHandler)];
            }
        }

        return $uids;
    }
}
