<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\DataHandling;

use SBUERK\Seeder\Seeding\Definition\SeedDefinition;
use SBUERK\Seeder\Seeding\Definition\SeedRecord;
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
 * @internal Part of the seeding implementation, not public API.
 */
final readonly class RecordSeeder
{
    public function __construct(
        private DataMapFactory $dataMapFactory,
    ) {}

    /**
     * @param BackendUserAuthentication $backendUser The user the write is
     *        performed as. It has to be an admin, see below.
     * @param int $rootPageId The page the top level records are written below.
     * @return array<string, int> The uids the records were written with, keyed
     *         by their seed identifier.
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

        $map = $this->dataMapFactory->createFromDefinition($definition, $rootPageId);
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

        if ($dataHandler->errorLog !== []) {
            // DataHandler reports a refused write by logging it and carrying
            // on, so a run that wrote half a page tree looks exactly like one
            // that wrote all of it. Turning the log into an exception is what
            // makes the difference visible.
            throw new SeedingFailedException(
                sprintf(
                    'Seeding "%s" failed: %s',
                    $definition->identifier,
                    implode(' | ', $dataHandler->errorLog),
                ),
                1787075003,
            );
        }

        return $this->collectWrittenUids($definition->records, $dataHandler);
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
