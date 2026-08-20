<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Functional\Seeding\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\DataHandling\FileReferenceSeeder;
use SBUERK\Seeder\Seeding\DataHandling\FileSeeder;
use SBUERK\Seeder\Seeding\DataHandling\ScenarioSeeder;
use SBUERK\Seeder\Seeding\DataHandling\ScenarioSeedResult;
use SBUERK\Seeder\Seeding\Definition\SeedDefinition;
use SBUERK\Seeder\Seeding\Scenario\ScenarioComposer;
use SBUERK\Seeder\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * What `versionVariants`, a `version:` item and a `discard` action put in the
 * database.
 *
 * This is the one test class that pays for `EXT:workspaces`. Installing
 * `typo3/cms-workspaces` is not the same as loading it: without the extension
 * in `$coreExtensionsToLoad` the table `sys_workspace` does not exist in the
 * test instance, a record carrying a `t3ver_wsid` has nothing to point at, and
 * the whole workspace half of the engine cannot be written at all - which is
 * why {@see \SBUERK\Seeder\Tests\Functional\Seeding\Scenario\DataHandlerWriterTest}
 * says in its own docblock that it does not cover it.
 *
 * The workspaces themselves are records of the scenario, exactly as TYPO3
 * Core's `CommonScenario.yaml` declares them. `sys_workspace` is an ordinary
 * table, so nothing in this extension needs to know about it.
 */
final class WorkspaceSeedingTest extends AbstractFunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(dirname(__DIR__, 2) . '/Fixtures/Database/BackendUsers.csv');
    }

    #[Test]
    public function theWorkspacesTheSetVersionsRecordsInAreSeededByTheSameSet(): void
    {
        $this->seed();

        $this->assertSame(
            [['uid' => 1, 'title' => 'Review'], ['uid' => 2, 'title' => 'Staging']],
            array_map(
                static fn(array $row): array => ['uid' => (int)$row['uid'], 'title' => $row['title']],
                $this->rows('sys_workspace', 'title'),
            ),
        );
    }

    #[Test]
    public function aVersionVariantBecomesAWorkspaceOverlayOfTheRecordItVersions(): void
    {
        $this->seed();

        $overlay = $this->overlayOf('tt_content', 301);

        $this->assertSame(1, $overlay['t3ver_wsid']);
        $this->assertSame('Review: changed', $overlay['header']);
    }

    #[Test]
    public function theRecordAVersionVariantVersionsKeepsItsOwnValuesInTheLiveWorkspace(): void
    {
        $this->seed();

        $live = $this->row('tt_content', 301);

        $this->assertSame(0, (int)$live['t3ver_wsid']);
        $this->assertSame(0, (int)$live['t3ver_oid']);
        $this->assertSame('Live, changed in review', $live['header']);
    }

    #[Test]
    public function aRecordWithoutAVersionVariantIsWrittenOnceAndOnlyLive(): void
    {
        $this->seed();

        $this->assertSame(
            [0],
            array_map(
                static fn(array $row): int => (int)$row['t3ver_wsid'],
                $this->rowsOf('tt_content', 300),
            ),
        );
    }

    #[Test]
    public function aVersionVariantOfANodeRecordVersionsThePageItself(): void
    {
        $this->seed();

        $overlay = $this->overlayOf('pages', 100);

        $this->assertSame(1, $overlay['t3ver_wsid']);
        $this->assertSame('Review root', $overlay['title']);
        $this->assertSame('Live root', $this->row('pages', 100)['title']);
    }

    #[Test]
    public function twoWorkspacesEachGetAVersionOfTheSameRecord(): void
    {
        $this->seed();

        // Every workspace round runs on the same `DataHandler`, and
        // `DataHandler::$autoVersionIdMap` is not reset by `start()`. Without
        // the reset `DataHandlerWriter::invokeFactory()` does, the second round
        // finds the version the first round auto-created, writes its values
        // into it, and the second workspace ends up with no record at all -
        // silently, with an empty error log.
        $this->assertSame('Review: changed too', $this->overlayOf('tt_content', 302, 1)['header']);
        $this->assertSame('Staging: changed as well', $this->overlayOf('tt_content', 302, 2)['header']);
        $this->assertSame('Live, changed in two workspaces', $this->row('tt_content', 302)['header']);
    }

    #[Test]
    public function aTopLevelVersionItemIsWrittenInItsWorkspaceWithTheUidItDeclares(): void
    {
        $this->seed();

        // Unlike a version variant, a `version:` item is a record of its own:
        // it gets a fresh identifier and therefore keeps the uid its `id`
        // suggested. It exists only in the workspace it names.
        $page = $this->row('pages', 900);

        $this->assertSame('Only in review', $page['title']);
        $this->assertSame(1, (int)$page['t3ver_wsid']);
    }

    #[Test]
    public function aDiscardActionIsAcceptedByTheEngineAndIgnoredByTheDataHandler(): void
    {
        $this->seed();

        // `discard` becomes a command map entry `['clearWSID' => true]`, and
        // only for a workspace greater than zero - the factory drops it
        // otherwise. `DataHandler::process_cmdmap()` switches over the command
        // name and knows no `clearWSID`: the spelling it still understands is
        // `['version' => ['action' => 'clearWSID']]`, which it forwards to
        // `discard()` under a `@todo` naming the testing framework as the last
        // caller. An unknown command falls through the switch with no branch
        // and no log entry, so the version survives and the seed reports
        // success.
        //
        // The action has no consumer in TYPO3 Core either - it is one of the
        // three the upstream format declares and nothing uses. Repairing it
        // means changing the ported `DataHandlerFactory`, which the upstream
        // conformance test holds to the letter, and is therefore a decision
        // for the step that narrows that test rather than for this one.
        $this->assertSame('Review: discarded', $this->overlayOf('tt_content', 303)['header']);
        $this->assertSame('Live, versioned and discarded again', $this->row('tt_content', 303)['header']);
    }

    #[Test]
    public function theSeedFailsRatherThanReportingAWorkspaceRoundThatDidNotWrite(): void
    {
        // The point of the whole class in one assertion: no error was logged
        // for any of the four rounds this scenario produces.
        $this->assertSame(
            ['sys_workspace' => 2, 'pages' => 3, 'tt_content' => 8],
            $this->seed()->recordCounts,
        );
    }

    private function seed(): ScenarioSeedResult
    {
        $definition = new SeedDefinition(
            identifier: 'workspace-seeding',
            title: 'Workspace versions',
            basePath: dirname(__DIR__, 2) . '/Fixtures/Scenarios',
            scenarios: ['WorkspaceScenario.yaml'],
        );
        $seeder = new ScenarioSeeder(
            new FileSeeder(GeneralUtility::makeInstance(StorageRepository::class)),
            new FileReferenceSeeder(GeneralUtility::makeInstance(ConnectionPool::class)),
        );

        return $seeder->seed(
            $definition,
            (new ScenarioComposer())->compose($definition, 0),
            $this->setUpBackendUser(1),
        );
    }

    /**
     * The workspace overlay of a live record: the row whose `t3ver_oid` names
     * it. Read rather than declared, because the uid an auto-created version
     * gets is assigned by the database - a version variant cannot declare an
     * `id`, and the engine refuses one with 1574365936.
     *
     * @return array<string, mixed>
     */
    private function overlayOf(string $table, int $liveUid, int $workspaceId = 1): array
    {
        $rows = $this->rowsOf($table, $liveUid, $workspaceId);
        $this->assertCount(1, $rows, sprintf('Expected one %s overlay of %d in workspace %d.', $table, $liveUid, $workspaceId));
        $row = $rows[0];
        $row['t3ver_wsid'] = (int)$row['t3ver_wsid'];

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsOf(string $table, int $liveUid, ?int $workspaceId = null): array
    {
        $rows = [];
        foreach ($this->rows($table, 't3ver_oid', 't3ver_wsid', ...$this->labelColumns($table)) as $row) {
            $isLive = (int)$row['uid'] === $liveUid && (int)$row['t3ver_oid'] === 0;
            $isOverlay = (int)$row['t3ver_oid'] === $liveUid;
            if (!$isLive && !$isOverlay) {
                continue;
            }
            if ($workspaceId !== null && ((int)$row['t3ver_wsid'] !== $workspaceId || !$isOverlay)) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $table, int $uid): array
    {
        foreach ($this->rows($table, 't3ver_oid', 't3ver_wsid', ...$this->labelColumns($table)) as $row) {
            if ((int)$row['uid'] === $uid) {
                return $row;
            }
        }

        $this->fail(sprintf('No %s record with uid %d.', $table, $uid));
    }

    /**
     * @return list<string>
     */
    private function labelColumns(string $table): array
    {
        return $table === 'tt_content' ? ['header'] : ['title'];
    }

    /**
     * Reads a table without restrictions, so what is asserted is what the
     * seeder wrote rather than what a workspace aware query would show.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string ...$columns): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select('uid', ...$columns)
            ->from($table)
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }
}
