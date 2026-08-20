<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Functional\Seeding\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\DataHandling\FileReferenceSeeder;
use SBUERK\Seeder\Seeding\DataHandling\FileSeeder;
use SBUERK\Seeder\Seeding\DataHandling\ScenarioSeeder;
use SBUERK\Seeder\Seeding\DataHandling\ScenarioSeedResult;
use SBUERK\Seeder\Seeding\Definition\SeedDefinition;
use SBUERK\Seeder\Seeding\Exception\SeedingFailedException;
use SBUERK\Seeder\Seeding\Scenario\DataHandlerFactory;
use SBUERK\Seeder\Seeding\Scenario\ScenarioComposer;
use SBUERK\Seeder\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writes a composed scenario into the installation.
 *
 * This is the class that turns the scenario engine - which was written for a
 * test run that has already authenticated an admin and does not care whether a
 * write failed - into something a console command may run against a real
 * installation. What is asserted here is exactly that difference: the admin
 * requirement, `isImporting`, the error log becoming an exception, and the map
 * from what the scenario declared to what the database ended up with.
 *
 * Every row is read back through the `QueryBuilder`. Hand written SQL would
 * pass here and fail on PostgreSQL, which folds an unquoted identifier to lower
 * case - `SELECT CType` asks for a column `ctype` that does not exist.
 */
final class ScenarioSeederTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(dirname(__DIR__, 2) . '/Fixtures/Database/BackendUsers.csv');
    }

    /**
     * The subject, constructed rather than fetched from the container: the
     * services are private and their wiring is proven where the command injects
     * them, not by publishing them for a test.
     */
    private function subject(): ScenarioSeeder
    {
        return new ScenarioSeeder(
            new FileSeeder(GeneralUtility::makeInstance(StorageRepository::class)),
            new FileReferenceSeeder(GeneralUtility::makeInstance(ConnectionPool::class)),
        );
    }

    /**
     * @param list<string> $scenarios
     */
    private function definition(array $scenarios, string $identifier = 'scenario-seeding'): SeedDefinition
    {
        return new SeedDefinition(
            identifier: $identifier,
            title: 'A scenario to seed',
            basePath: dirname(__DIR__, 2) . '/Fixtures/Scenarios',
            scenarios: $scenarios,
        );
    }

    private function factory(SeedDefinition $definition, int $rootPageId = 0): DataHandlerFactory
    {
        return (new ScenarioComposer())->compose($definition, $rootPageId);
    }

    private function seed(
        SeedDefinition $definition,
        int $rootPageId = 0,
        ?BackendUserAuthentication $backendUser = null,
    ): ScenarioSeedResult {
        return $this->subject()->seed(
            $definition,
            $this->factory($definition, $rootPageId),
            $backendUser ?? $this->setUpBackendUser(1),
        );
    }

    /**
     * Reads a table without restrictions, so what is asserted is what the
     * seeder wrote rather than what happens to be visible.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string ...$columns): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select(...$columns)
            ->from($table)
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }

    #[Test]
    public function aScenarioIsWrittenWithTheUidsItDeclares(): void
    {
        $this->seed($this->definition(['PageTreeScenario.yaml']));

        $this->assertSame(
            [
                ['uid' => 100, 'pid' => 0, 'title' => 'Root'],
                ['uid' => 110, 'pid' => 100, 'title' => 'First child'],
                ['uid' => 120, 'pid' => 100, 'title' => 'Second child'],
            ],
            $this->rows('pages', 'uid', 'pid', 'title'),
        );
        $this->assertSame(
            [
                ['uid' => 300, 'pid' => 100, 'header' => 'First on root'],
                ['uid' => 301, 'pid' => 100, 'header' => 'Second on root'],
            ],
            $this->rows('tt_content', 'uid', 'pid', 'header'),
        );
    }

    #[Test]
    public function theResultMapsDeclaredUidsToWrittenUids(): void
    {
        $result = $this->seed($this->definition(['PageTreeScenario.yaml']));

        $this->assertSame(
            [
                'pages:100' => 100,
                'pages:110' => 110,
                'pages:120' => 120,
                'tt_content:300' => 300,
                'tt_content:301' => 301,
            ],
            $result->writtenUids,
        );
        $this->assertSame(['pages' => 3, 'tt_content' => 2], $result->recordCounts);
        $this->assertSame(5, $result->recordCount());
        $this->assertSame([100, 110, 120], $result->pageUids());
    }

    /**
     * The declaration order of the siblings, which the factory expresses as a
     * negative pid and DataHandler turns into "insert after that record".
     */
    #[Test]
    public function siblingsKeepTheOrderTheyWereDeclaredIn(): void
    {
        $this->seed($this->definition(['PageTreeScenario.yaml']));

        $rows = $this->rows('pages', 'uid', 'sorting');
        $this->assertGreaterThan((int)$rows[1]['sorting'], (int)$rows[2]['sorting']);
    }

    /**
     * The other accepted spelling of a scenario path, and the one a set naming
     * a scenario of another extension has to use. It is covered here rather
     * than in the unit test of the composer because resolving it means asking
     * the package manager for a package that really exists - and how
     * `GeneralUtility::getFileAbsFileName()` gets there differs between core
     * versions, so a mock of that call is a statement about one of them.
     */
    #[Test]
    public function anExtensionPathIsResolvedThroughTheCore(): void
    {
        $definition = new SeedDefinition(
            identifier: 'scenario-seeding',
            title: 'A scenario named by an extension path',
            scenarios: ['EXT:seeder/Tests/Functional/Fixtures/Scenarios/PageTreeScenario.yaml'],
        );

        $result = $this->subject()->seed(
            $definition,
            (new ScenarioComposer())->compose($definition),
            $this->setUpBackendUser(1),
        );

        $this->assertSame(['pages' => 3, 'tt_content' => 2], $result->recordCounts);
    }

    #[Test]
    public function severalScenarioFilesAreComposedIntoOneRun(): void
    {
        $result = $this->seed($this->definition(['PageTreeScenario.yaml', 'SecondPageTreeScenario.yaml']));

        $this->assertSame(['pages' => 5, 'tt_content' => 2], $result->recordCounts);
        $this->assertSame(
            [100, 110, 120, 200, 210],
            array_column($this->rows('pages', 'uid'), 'uid'),
        );
    }

    /**
     * The second file declares no `entitySettings` of its own, so its records
     * are only written to `pages` at all because the settings of the first file
     * are merged into the same scenario.
     */
    #[Test]
    public function composingMergesTheEntitySettingsOfEveryFile(): void
    {
        $this->seed($this->definition(['PageTreeScenario.yaml', 'SecondPageTreeScenario.yaml']));

        $this->assertSame(
            [['uid' => 200, 'pid' => 0], ['uid' => 210, 'pid' => 200]],
            array_values(array_filter(
                $this->rows('pages', 'uid', 'pid'),
                static fn(array $row): bool => (int)$row['uid'] >= 200,
            )),
        );
    }

    #[Test]
    public function aRootPageMovesTheTopLevelRecordsOfTheSet(): void
    {
        $this->seed($this->definition(['PageTreeScenario.yaml']));
        $this->seed($this->definition(['ContentOnlyScenario.yaml']), 110);

        $this->assertSame(
            [['uid' => 500, 'pid' => 110], ['uid' => 501, 'pid' => 120]],
            array_values(array_filter(
                $this->rows('tt_content', 'uid', 'pid'),
                static fn(array $row): bool => (int)$row['uid'] >= 500,
            )),
        );
    }

    #[Test]
    public function aNonAdminBackendUserIsRefused(): void
    {
        $definition = $this->definition(['PageTreeScenario.yaml']);

        $this->expectException(SeedingFailedException::class);
        $this->expectExceptionCode(1787075001);

        $this->seed($definition, 0, $this->setUpBackendUser(2));
    }

    #[Test]
    public function nothingIsWrittenWhenTheBackendUserIsRefused(): void
    {
        try {
            $this->seed($this->definition(['PageTreeScenario.yaml']), 0, $this->setUpBackendUser(2));
        } catch (SeedingFailedException) {
            // The subject of the assertion below.
        }

        $this->assertSame([], $this->rows('pages', 'uid'));
    }

    /**
     * A failed write has to be loud. `DataHandlerWriter` collects the
     * DataHandler error log and returns, which for a seed means a set that
     * half wrote itself and reported success.
     */
    #[Test]
    public function anErrorOfTheDataHandlerBecomesAnException(): void
    {
        $definition = $this->definition(['FailingActionScenario.yaml']);

        $this->expectException(SeedingFailedException::class);
        $this->expectExceptionCode(1787075003);

        $this->seed($definition);
    }

    /**
     * `DataHandler::$isImporting` suppresses the `autogenerated-<uid>` site
     * configuration `CreateSiteConfiguration` writes for a new site root. It is
     * never what a seed wanted, and it is written before the set gets to write
     * its own.
     */
    #[Test]
    public function noSiteConfigurationIsWrittenForASeededSiteRoot(): void
    {
        $this->seed($this->definition(['PageTreeScenario.yaml']));

        $this->assertSame([], glob($this->instancePath . '/config/sites/*') ?: []);
    }
}
