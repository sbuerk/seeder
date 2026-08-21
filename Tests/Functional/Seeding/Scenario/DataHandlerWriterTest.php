<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Functional\Seeding\Scenario;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\DataFactory\Seeding\Scenario\DataHandlerFactory;
use SBUERK\DataFactory\Seeding\Scenario\DataHandlerWriter;
use SBUERK\DataFactory\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * What `DataHandlerWriter` does with the maps a `DataHandlerFactory` produced.
 *
 * The class is 130 lines of glue, and every one of the behaviours below is the
 * kind that fails silently when it breaks: a suggested uid that arrives too
 * late produces a tree with the right shape and the wrong uids, a backend user
 * that is not cloned leaks a workspace into the caller, a command map that runs
 * too early deletes a record that does not exist yet, and a `NEW` identifier
 * that is not substituted writes a new record where an existing one should have
 * been updated. None of it is visible in a row count.
 *
 * This is a functional test because there is nothing to observe without a real
 * `DataHandler`: TCA, a database and a backend user are what the class hands
 * its maps to.
 *
 * ## Reading the maps the writer actually handed over
 *
 * Two of the behaviours - the `pid` that is dropped for an already numeric key,
 * and what the command map keeps - have no effect a database row can show:
 * `DataHandler::fillInFieldArray()` ignores an incoming `pid` for both an
 * insert and an update ("Nothing happens, already set"), so a map with the
 * `pid` and a map without it write the same row. `DataHandler::$datamap` cannot
 * be read after the run either, since `process_datamap()` rewrites it in place.
 *
 * They are therefore observed where the map is still the one the writer passed:
 * in the `processDatamapClass` / `processCmdmapClass` hooks, whose
 * `processDatamap_beforeStart()` and `processCmdmap_beforeStart()` methods are
 * called with the `DataHandler` before it touches anything.
 * {@see RecordingDataHandlerHook} does nothing but record. If a future core
 * drops those hooks this test fails loudly, which is the intent - a silently
 * empty recording would be worse.
 *
 * ## What is not covered here, and why
 *
 * `EXT:workspaces` is not loaded in this test instance, so `sys_workspace` does
 * not exist and a data map for a workspace other than the live one cannot be
 * written at all. The per workspace loop is covered here for the round it can
 * run - the clone of the backend user is asserted on the live workspace - and
 * what needs more than one round is covered by
 * {@see \SBUERK\DataFactory\Tests\Functional\Seeding\DataHandling\WorkspaceSeedingTest},
 * which loads the extension and pays the setup cost once.
 */
final class DataHandlerWriterTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(dirname(__DIR__, 2) . '/Fixtures/Database/BackendUsers.csv');

        RecordingDataHandlerHook::reset();
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
            = RecordingDataHandlerHook::class;
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
            = RecordingDataHandlerHook::class;
    }

    protected function tearDown(): void
    {
        RecordingDataHandlerHook::reset();

        parent::tearDown();
    }

    #[Test]
    public function withBackendUserWritesEveryRecordOfAScenarioWithTheUidItDeclares(): void
    {
        $writer = DataHandlerWriter::withBackendUser($this->backendUser(1));

        $writer->invokeFactory($this->factory('PageTreeScenario.yaml'));

        $this->assertSame([], $writer->getErrors());
        $this->assertSame(
            [
                ['uid' => 100, 'pid' => 0, 'title' => 'Root'],
                ['uid' => 110, 'pid' => 100, 'title' => 'First child'],
                ['uid' => 120, 'pid' => 100, 'title' => 'Second child'],
            ],
            $this->rows('pages', ['uid', 'pid', 'title'])
        );
        $this->assertSame(
            [
                ['uid' => 300, 'pid' => 100, 'header' => 'First on root'],
                ['uid' => 301, 'pid' => 100, 'header' => 'Second on root'],
            ],
            $this->rows('tt_content', ['uid', 'pid', 'header'])
        );
        // The factory chains the "pid" of every sibling after the first to
        // "-<predecessor>", so the tree comes out in declaration order rather
        // than reversed, which is what DataHandler would do on its own.
        $this->assertSame(
            ['First child', 'Second child'],
            array_column($this->rows('pages', ['title', 'sorting'], 'sorting', 'pid = 100'), 'title')
        );
    }

    #[Test]
    public function withBackendUserTakesTheCopyLevelsOfTheUserAsCopyTree(): void
    {
        $backendUser = $this->backendUser(1);
        $backendUser->uc['copyLevels'] = 5;

        $dataHandler = $this->dataHandlerOf(DataHandlerWriter::withBackendUser($backendUser));

        // Asked through reflection rather than with property_exists(), which
        // PHPStan narrows to always-true against the core that happens to be
        // installed - the point of the shim is that the answer differs between
        // core versions, so the question has to survive static analysis.
        $reflection = new \ReflectionClass($dataHandler);
        if ($reflection->hasProperty('copyTree')) {
            $this->assertSame(5, $reflection->getProperty('copyTree')->getValue($dataHandler));
        } else {
            // The assignment is guarded by the same property_exists() check.
            // Writing to an undeclared property is deprecated as of PHP 8.2 and
            // this suite fails on a deprecation, so a core without the property
            // must not have gained one here.
            $this->assertObjectNotHasProperty('copyTree', $dataHandler);
        }
    }

    #[Test]
    public function withBackendUserLeavesCopyTreeAloneWhenTheUserHasNoCopyLevels(): void
    {
        $backendUser = $this->backendUser(1);
        unset($backendUser->uc['copyLevels']);

        $dataHandler = $this->dataHandlerOf(DataHandlerWriter::withBackendUser($backendUser));

        // v14 has no `copyTree` at all, which is the whole reason the shim in
        // `withBackendUser()` asks before assigning. Reflection keeps the
        // question runtime-answerable on both core versions.
        $reflection = new \ReflectionClass($dataHandler);
        if ($reflection->hasProperty('copyTree')) {
            $property = $reflection->getProperty('copyTree');
            $this->assertSame(
                $property->getValue(GeneralUtility::makeInstance(DataHandler::class)),
                $property->getValue($dataHandler)
            );
        } else {
            $this->assertObjectNotHasProperty('copyTree', $dataHandler);
        }
    }

    #[Test]
    public function theSuggestedIdsAreHandedToDataHandlerBeforeTheFirstRecordIsProcessed(): void
    {
        $factory = $this->factory('PageTreeScenario.yaml');
        $writer = $this->recording(DataHandlerWriter::withBackendUser($this->backendUser(1)));

        $writer->invokeFactory($factory);

        $this->assertSame(
            [
                'pages:100' => true,
                'pages:110' => true,
                'pages:120' => true,
                'tt_content:300' => true,
                'tt_content:301' => true,
            ],
            $factory->getSuggestedIds()
        );
        // Recorded before the first record was processed, which is the only
        // point at which assigning them still has an effect.
        $this->assertSame(
            $factory->getSuggestedIds(),
            RecordingDataHandlerHook::$suggestedInsertUids[0] ?? []
        );
        $this->assertSame([100, 110, 120], array_column($this->rows('pages', ['uid']), 'uid'));
    }

    /**
     * `DataHandler::insertDB()` evaluates a suggested uid behind
     * `if ($this->BE_USER->isAdmin() && $suggestedUid && …)` and says nothing
     * when the check fails. The writer does not check for an admin, so a
     * scenario written by a non admin comes out with different uids than it
     * declares - or, as here, does not come out at all: a user without any
     * group cannot modify a table, which DataHandler does report.
     *
     * Whichever of the two happens, it happens without an exception, which is
     * the point: the caller has to look at `getErrors()`.
     */
    #[Test]
    public function aNonAdminBackendUserProducesErrorsInsteadOfAnException(): void
    {
        $writer = DataHandlerWriter::withBackendUser($this->backendUser(2));

        $writer->invokeFactory($this->factory('PageTreeScenario.yaml'));

        $this->assertNotSame([], $writer->getErrors());
        $this->assertSame([], $this->rows('pages', ['uid']));
        $this->assertSame([], $this->rows('tt_content', ['uid']));
    }

    #[Test]
    public function everyRoundRunsOnACloneSoTheWorkspaceOfTheCallersUserIsUntouched(): void
    {
        $backendUser = $this->backendUser(1);
        $backendUser->workspace = 4711;

        $writer = $this->recording(DataHandlerWriter::withBackendUser($backendUser));
        $writer->invokeFactory($this->factory('PageTreeScenario.yaml'));

        $usedBackendUser = RecordingDataHandlerHook::$backendUsers[0] ?? null;
        $this->assertInstanceOf(BackendUserAuthentication::class, $usedBackendUser);
        $this->assertNotSame($backendUser, $usedBackendUser);
        $this->assertSame($backendUser->user['uid'] ?? null, $usedBackendUser->user['uid'] ?? null);
        // The clone is switched to the workspace of the data map, the original
        // keeps whatever the caller had set.
        $this->assertSame(0, $usedBackendUser->workspace);
        $this->assertSame(4711, $backendUser->workspace);
    }

    #[Test]
    public function theCommandMapIsProcessedAfterEveryDataMap(): void
    {
        $writer = $this->recording(DataHandlerWriter::withBackendUser($this->backendUser(1)));

        $writer->invokeFactory($this->factory('ActionsScenario.yaml'));

        $this->assertSame([], $writer->getErrors());
        $this->assertSame(
            ['dataMap', 'commandMap'],
            RecordingDataHandlerHook::$rounds
        );
        // Every command names a record of the same scenario, so all three of
        // them only work because the data map was written first.
        $this->assertSame(
            [
                ['uid' => 300, 'pid' => 100, 'deleted' => 0],
                ['uid' => 301, 'pid' => 100, 'deleted' => 1],
                ['uid' => 302, 'pid' => 110, 'deleted' => 0],
                ['uid' => 303, 'pid' => 100, 'deleted' => 0],
                ['uid' => 304, 'pid' => 100, 'deleted' => 0],
            ],
            $this->rows('tt_content', ['uid', 'pid', 'deleted'])
        );
    }

    #[Test]
    public function aMoveActionPutsTheRecordWhereItNamesAndNotWhereItWasWritten(): void
    {
        $writer = $this->recording(DataHandlerWriter::withBackendUser($this->backendUser(1)));

        $writer->invokeFactory($this->factory('ActionsScenario.yaml'));

        $this->assertSame([], $writer->getErrors());
        // "301" was deleted and "302" moved away, so three elements are left
        // on the page. Then "303" went to the top of it and "304" behind
        // "303" - and the second of those is the assertion that matters: the
        // sequential ordering the factory builds already leaves "304" right
        // behind "300", so an "afterRecord" that never reached the command map
        // would produce 303, 300, 304 and look just as deliberate.
        $this->assertSame([303, 304, 300], $this->uidsInSortingOrder('tt_content', 100));
    }

    #[Test]
    public function theCommandMapKeepsWhatItSubstitutesAndAddsNothing(): void
    {
        $writer = $this->recording(DataHandlerWriter::withBackendUser($this->backendUser(1)));

        $writer->invokeFactory($this->factory('ActionsScenario.yaml'));

        // Every key was a "NEW" identifier the data map round resolved, and the
        // "move" of "toTop" was the "NEW" identifier of the node page - both
        // came back as the integer uid "substNEWwithIDs" holds. Nothing
        // else is touched: unlike the data map, the command map keeps every
        // value of a numeric key - it has no "pid" to drop, since a command map
        // entry only ever holds "move", "delete" or "clearWSID".
        $this->assertSame(
            [
                'tt_content' => [
                    301 => ['delete' => true],
                    302 => ['move' => 110],
                    303 => ['move' => 100],
                    304 => ['move' => '-303'],
                ],
            ],
            RecordingDataHandlerHook::$commandMaps[0] ?? []
        );
    }

    #[Test]
    public function aNewIdentifierTheDataHandlerKnowsBecomesTheRecordItNames(): void
    {
        $this->writeSingleContentScenario();
        $factory = $this->factory('SingleContentUpdateScenario.yaml');
        $writer = $this->writerKnowing($factory, ['pages', 'tt_content']);

        $writer->invokeFactory($factory);

        $this->assertSame([], $writer->getErrors());
        // The page and the content element were updated in place - the second
        // page, whose identifier resolved to nothing, is the only new record,
        // and it was created where it was declared: next to the root page, not
        // below it.
        $this->assertSame(
            [
                ['uid' => 100, 'pid' => 0, 'title' => 'Updated'],
                ['uid' => 110, 'pid' => 0, 'title' => 'Declared as a sibling of the root page'],
            ],
            $this->rows('pages', ['uid', 'pid', 'title'])
        );
        $this->assertSame(
            [['uid' => 300, 'pid' => 100, 'header' => 'Updated content']],
            $this->rows('tt_content', ['uid', 'pid', 'header'])
        );
    }

    #[Test]
    public function theDataMapSubstitutesKnownIdentifiersDropsThePidOfANumericKeyAndLeavesTheRestAlone(): void
    {
        $this->writeSingleContentScenario();
        $factory = $this->factory('SingleContentUpdateScenario.yaml');
        $writer = $this->recording($this->writerKnowing($factory, ['pages', 'tt_content']));
        $unresolvedIdentifier = array_keys($factory->getDataMapPerWorkspace()[0]['pages'])[1];

        $writer->invokeFactory($factory);

        $dataMap = RecordingDataHandlerHook::$dataMaps[0] ?? [];
        $this->assertSame([100, $unresolvedIdentifier], array_keys($dataMap['pages'] ?? []));
        $this->assertSame([300], array_keys($dataMap['tt_content'] ?? []));
        // A key that resolved to a record is numeric now, and a "pid" would be
        // a statement about where to create a record that already exists.
        $this->assertArrayNotHasKey('pid', $dataMap['pages'][100]);
        $this->assertArrayNotHasKey('pid', $dataMap['tt_content'][300]);
        // A key that resolved to nothing stays what it was, "pid" included.
        $this->assertSame($unresolvedIdentifier, array_keys($dataMap['pages'])[1]);
        // Values that are not strings are handed over untouched.
        $this->assertSame(110, $dataMap['pages'][$unresolvedIdentifier]['uid']);
        $this->assertSame(100, $dataMap['pages'][100]['uid']);
    }

    /**
     * The second page of the scenario is declared as a sibling of the root
     * page, so the factory chained its `pid` to `-<identifier of the root
     * page>` - "create me on the page of that record, right after it".
     *
     * `typo3/testing-framework` 9.6.1 resolves that form as
     * `substNEWwithIDs[substr($value, 1)]`, which looks the identifier up
     * without its leading minus and returns the uid without it as well, so the
     * "insert after" marker is lost: a plain page id reaches `DataHandler` and
     * the record is created *inside* the record it was meant to be placed
     * after. This port puts the sign back, which is the third divergence listed
     * on `DataHandlerWriter`.
     *
     * The unit tests of the substitution itself are in
     * `Tests/Unit/Seeding/Scenario/DataHandlerWriterSubstitutionTest`. What
     * this adds is that `DataHandler` reads the result the way the sign says.
     */
    #[Test]
    public function aResolvedMinusNewValueKeepsItsSignAndStaysAPosition(): void
    {
        $this->writeSingleContentScenario();
        $factory = $this->factory('SingleContentUpdateScenario.yaml');
        $writer = $this->recording($this->writerKnowing($factory, ['pages', 'tt_content']));
        $unresolvedIdentifier = array_keys($factory->getDataMapPerWorkspace()[0]['pages'])[1];
        $declaredPid = $factory->getDataMapPerWorkspace()[0]['pages'][$unresolvedIdentifier]['pid'];

        $writer->invokeFactory($factory);

        $dataMap = RecordingDataHandlerHook::$dataMaps[0] ?? [];
        $this->assertStringStartsWith('-NEW', (string)$declaredPid);
        $this->assertSame('-100', $dataMap['pages'][$unresolvedIdentifier]['pid']);
        // And so the page ends up next to the root page, which is where it was
        // declared.
        $this->assertSame(
            [['uid' => 110, 'pid' => 0]],
            $this->rows('pages', ['uid', 'pid'], 'uid', 'uid = 110')
        );
    }

    #[Test]
    public function anErrorOfTheCommandMapRoundIsCollectedAfterASuccessfulDataMapRound(): void
    {
        $writer = $this->recording(DataHandlerWriter::withBackendUser($this->backendUser(1)));

        $writer->invokeFactory($this->factory('FailingActionScenario.yaml'));

        // The data map round wrote its records without complaining, and the
        // refused move left the tree as it was ...
        $this->assertSame(
            [
                ['uid' => 100, 'pid' => 0, 'title' => 'Root'],
                ['uid' => 110, 'pid' => 100, 'title' => 'Child'],
            ],
            $this->rows('pages', ['uid', 'pid', 'title'])
        );
        $this->assertSame(['dataMap', 'commandMap'], RecordingDataHandlerHook::$rounds);
        // ... and the error of the command map round that followed is what
        // "getErrors()" holds. It is collected, not thrown: "invokeFactory()"
        // returned normally.
        $this->assertCount(1, $writer->getErrors());
        $this->assertStringContainsString('rootline', $writer->getErrors()[0]);
    }

    /**
     * The one failure the writer does not turn into an entry of `getErrors()`.
     *
     * A command map key that no round resolved stays the `NEW` identifier it
     * was, and `DataHandler::start()` rejects a command map key that is not an
     * integer (`1708586979`, added in v13). The exception passes through
     * `invokeFactory()`, so a caller that only looks at `getErrors()` sees
     * nothing - which is worth knowing before this writer is used behind a
     * console command.
     */
    #[Test]
    public function anUnresolvedCommandMapKeyEscapesAsAnExceptionRatherThanAnError(): void
    {
        // A non admin user may not modify any table, so the data map round
        // writes nothing and resolves no identifier at all.
        $writer = DataHandlerWriter::withBackendUser($this->backendUser(2));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionCode(1708586979);

        $writer->invokeFactory($this->factory('ActionsScenario.yaml'));
    }

    /**
     * A backend user for the DataHandler, plus the language service its
     * messages are rendered with.
     */
    /**
     * The third constructor parameter is this repository's own, additive
     * divergence from `typo3/testing-framework`: without it the writer assigns
     * every suggested id, which is what upstream does and what every other test
     * here relies on.
     */
    #[Test]
    public function everySuggestedIdOfTheFactoryIsAssignedByDefault(): void
    {
        $factory = $this->factory('PageTreeScenario.yaml');
        $writer = new DataHandlerWriter(
            GeneralUtility::makeInstance(DataHandler::class),
            $this->backendUser(1)
        );

        $writer->invokeFactory($factory);

        $this->assertSame(
            $factory->getSuggestedIds(),
            $this->dataHandlerOf($writer)->suggestedInsertUids
        );
    }

    /**
     * A held back suggestion is not a record that is skipped: the row is still
     * written, DataHandler just assigns the uid instead of honouring the
     * declared one. That is what `data-factory:import --force` needs - the uid of a
     * table this installation already uses has to be given up, and giving up
     * the record would mean importing half a set.
     */
    #[Test]
    public function aHeldBackSuggestionIsNotAssignedAndTheRecordIsStillWritten(): void
    {
        $factory = $this->factory('PageTreeScenario.yaml');
        $writer = new DataHandlerWriter(
            GeneralUtility::makeInstance(DataHandler::class),
            $this->backendUser(1),
            ['pages:110' => true]
        );

        $writer->invokeFactory($factory);

        $this->assertSame([], $writer->getErrors());
        $this->assertArrayNotHasKey(
            'pages:110',
            $this->dataHandlerOf($writer)->suggestedInsertUids
        );
        $titles = array_column($this->rows('pages', ['uid', 'title']), 'title', 'uid');
        $this->assertContains('First child', $titles);
        $this->assertArrayNotHasKey(110, $titles);
        // The sibling after it chains its "pid" to "-NEW<first child>", so this
        // is what proves the identifier still resolves to the row that exists.
        // On PostgreSQL it did not until the "uid" was dropped along with the
        // suggestion: DataHandler returns the suggested number from
        // postProcessDatabaseInsert() whether it honoured it or not, and the
        // whole tree below a held back record then hangs off nothing.
        $this->assertSame(
            [['title' => 'First child', 'pid' => 100], ['title' => 'Second child', 'pid' => 100]],
            $this->rows('pages', ['title', 'pid'], 'sorting', 'pid = 100')
        );
    }

    /**
     * The uids of the records on one page in the order the backend and the
     * frontend list them, which is `sorting` and not `uid`.
     *
     * @return list<int>
     */
    private function uidsInSortingOrder(string $table, int $pageId): array
    {
        return array_map(
            static fn(array $row): int => (int)$row['uid'],
            $this->rows($table, ['uid'], 'sorting', sprintf('pid = %d AND deleted = 0', $pageId))
        );
    }

    private function backendUser(int $uid): BackendUserAuthentication
    {
        $backendUser = $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)
            ->createFromUserPreferences($backendUser);

        return $backendUser;
    }

    private function factory(string $fileName): DataHandlerFactory
    {
        $file = dirname(__DIR__, 2) . '/Fixtures/Scenarios/' . $fileName;
        $this->assertFileExists($file);

        return DataHandlerFactory::fromYamlFile($file);
    }

    /**
     * The `DataHandler` of a writer built by `withBackendUser()`, which keeps it
     * private - and rightly so, there is nothing to do with it from outside.
     */
    private function dataHandlerOf(DataHandlerWriter $writer): DataHandler
    {
        $property = new \ReflectionProperty(DataHandlerWriter::class, 'dataHandler');
        $dataHandler = $property->getValue($writer);
        $this->assertInstanceOf(DataHandler::class, $dataHandler);

        return $dataHandler;
    }

    /**
     * Records the rounds of this writer's `DataHandler` and of no other.
     */
    private function recording(DataHandlerWriter $writer): DataHandlerWriter
    {
        RecordingDataHandlerHook::$only = $this->dataHandlerOf($writer);

        return $writer;
    }

    /**
     * Writes `SingleContentScenario.yaml`, so the records the update scenario
     * names exist before it is written.
     */
    private function writeSingleContentScenario(): void
    {
        $writer = DataHandlerWriter::withBackendUser($this->backendUser(1));
        $writer->invokeFactory($this->factory('SingleContentScenario.yaml'));

        $this->assertSame([], $writer->getErrors());
        $this->assertSame([['uid' => 100]], $this->rows('pages', ['uid']));
        RecordingDataHandlerHook::reset();
    }

    /**
     * A writer whose `DataHandler` already resolves the first identifier of
     * each of the given tables - the state the second round of a multi
     * workspace run finds, which is the only way `updateDataMap()` has anything
     * to substitute at all.
     *
     * @param list<string> $tableNames
     */
    private function writerKnowing(DataHandlerFactory $factory, array $tableNames): DataHandlerWriter
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        foreach ($tableNames as $tableName) {
            $tableDataMap = $factory->getDataMapPerWorkspace()[0][$tableName] ?? [];
            $identifier = array_key_first($tableDataMap);
            $this->assertIsString($identifier);
            $dataHandler->substNEWwithIDs[$identifier] = (int)$tableDataMap[$identifier]['uid'];
        }

        return new DataHandlerWriter($dataHandler, $this->backendUser(1));
    }

    /**
     * Reads a table without restrictions - what was written, not what happens
     * to be visible - and through the QueryBuilder rather than as SQL, which
     * would fold "CType" to "ctype" on PostgreSQL.
     *
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, array $columns, string $orderBy = 'uid', string $where = ''): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select(...$columns)->from($table)->orderBy($orderBy);
        if ($where !== '') {
            $queryBuilder->where($where);
        }

        return array_map(
            static function (array $row): array {
                foreach (['uid', 'pid', 'deleted', 'sorting'] as $column) {
                    if (isset($row[$column])) {
                        $row[$column] = (int)$row[$column];
                    }
                }

                return $row;
            },
            $queryBuilder->executeQuery()->fetchAllAssociative()
        );
    }
}

/**
 * Records what a `DataHandler` was handed, before it starts changing it.
 *
 * Registered as a `processDatamapClass` / `processCmdmapClass` hook by
 * {@see DataHandlerWriterTest}, which is the only place either method is called
 * from. The properties are static because the hook object is instantiated by
 * the core, not by the test, and they are reset per test.
 *
 * @internal Part of `DataHandlerWriterTest`, not a fixture for anything else.
 */
final class RecordingDataHandlerHook
{
    /**
     * The one `DataHandler` whose rounds are recorded.
     *
     * Processing a command map lets `DataHandler` create further instances of
     * itself - moving a record is a `DataHandler` run of its own - and those
     * call the very same hooks. Without this filter the recording is a mix of
     * the writer's rounds and the core's own.
     */
    public static ?DataHandler $only = null;

    /**
     * Which round each recording came from, in the order they happened.
     *
     * @var list<string>
     */
    public static array $rounds = [];

    /**
     * @var list<array<string, array<string|int, mixed>>>
     */
    public static array $dataMaps = [];

    /**
     * @var list<array<string, array<string|int, mixed>>>
     */
    public static array $commandMaps = [];

    /**
     * @var list<BackendUserAuthentication>
     */
    public static array $backendUsers = [];

    /**
     * @var list<array<string, mixed>>
     */
    public static array $suggestedInsertUids = [];

    public static function reset(): void
    {
        self::$only = null;
        self::$rounds = [];
        self::$dataMaps = [];
        self::$commandMaps = [];
        self::$backendUsers = [];
        self::$suggestedInsertUids = [];
    }

    public function processDatamap_beforeStart(DataHandler $dataHandler): void
    {
        if (self::$only !== null && self::$only !== $dataHandler) {
            return;
        }
        self::$rounds[] = 'dataMap';
        self::$dataMaps[] = $dataHandler->datamap;
        self::$backendUsers[] = $dataHandler->BE_USER;
        self::$suggestedInsertUids[] = $dataHandler->suggestedInsertUids;
    }

    public function processCmdmap_beforeStart(DataHandler $dataHandler): void
    {
        if (self::$only !== null && self::$only !== $dataHandler) {
            return;
        }
        self::$rounds[] = 'commandMap';
        self::$commandMaps[] = $dataHandler->cmdmap;
        self::$backendUsers[] = $dataHandler->BE_USER;
    }
}
