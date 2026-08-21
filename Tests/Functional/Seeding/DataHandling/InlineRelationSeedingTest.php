<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Functional\Seeding\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\DataFactory\Seeding\DataHandling\FileImporterInterface;
use SBUERK\DataFactory\Seeding\DataHandling\FileReferenceSeeder;
use SBUERK\DataFactory\Seeding\DataHandling\FileSeeder;
use SBUERK\DataFactory\Seeding\DataHandling\ScenarioSeeder;
use SBUERK\DataFactory\Seeding\DataHandling\ScenarioSeedResult;
use SBUERK\DataFactory\Seeding\Definition\SeedDefinition;
use SBUERK\DataFactory\Seeding\Scenario\ScenarioComposer;
use SBUERK\DataFactory\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * An inline relation, seeded from a scenario file.
 *
 * This extension adds **nothing** for it, and that is what is under test. The
 * scenario format has no key for a relation, but it does not need one: an `id`
 * an entity declares is the uid its record is written with, so a parent can
 * name its children by listing those ids in its relation field, and
 * `DataHandler` resolves that list exactly as it resolves the one a backend
 * form submits.
 *
 * What it resolves it into is not in the scenario either. `parentid`,
 * `parenttable` and `sorting_foreign` are written by
 * `TYPO3\CMS\Core\Database\RelationHandler::writeForeignField()`, and which
 * columns those are comes from the TCA of the *parent field* - which is why
 * the tests read them back from the database rather than asserting a data map.
 *
 * The fixture extension `inline-relations` provides the relation, two levels
 * of it, so that "a child may itself have children" is proven rather than
 * assumed.
 *
 * Every row is read back through the `QueryBuilder`. Hand written SQL would
 * pass here and fail on PostgreSQL, which folds an unquoted identifier to lower
 * case - `SELECT CType` asks for a column `ctype` that does not exist.
 */
final class InlineRelationSeedingTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'sbuerk/data-factory',
        __DIR__ . '/../../Fixtures/Extensions/inline-relations',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(dirname(__DIR__, 2) . '/Fixtures/Database/BackendUsers.csv');
    }

    private function seed(): ScenarioSeedResult
    {
        $definition = new SeedDefinition(
            identifier: 'inline-relations',
            title: 'A content element with inline children',
            basePath: dirname(__DIR__, 2) . '/Fixtures/Scenarios',
            scenarios: ['InlineRelationScenario.yaml'],
        );

        return (new ScenarioSeeder(
            new FileSeeder(
                GeneralUtility::makeInstance(StorageRepository::class),
                $this->get(FileImporterInterface::class),
            ),
            new FileReferenceSeeder(GeneralUtility::makeInstance(ConnectionPool::class)),
        ))->seed(
            $definition,
            (new ScenarioComposer())->compose($definition),
            $this->setUpBackendUser(1),
        );
    }

    /**
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
    public function theChildrenAreTiedToTheirParentByTheColumnsOfTheRelation(): void
    {
        $this->seed();

        $this->assertSame(
            [
                ['uid' => 31, 'title' => 'One', 'parentid' => 21, 'parenttable' => 'tt_content'],
                ['uid' => 32, 'title' => 'Two', 'parentid' => 21, 'parenttable' => 'tt_content'],
            ],
            $this->rows('tx_testsinlinerelations_item', 'uid', 'title', 'parentid', 'parenttable'),
        );
    }

    /**
     * The declared list is "32,31", so an assertion of "1, 2" here would pass
     * on the uid order as well - which is exactly what it must not do.
     */
    #[Test]
    public function theOrderOfTheChildrenComesFromTheDeclaredListAndNotFromTheirUids(): void
    {
        $this->seed();

        $this->assertSame(
            [
                ['uid' => 31, 'sorting_foreign' => 2],
                ['uid' => 32, 'sorting_foreign' => 1],
            ],
            $this->rows('tx_testsinlinerelations_item', 'uid', 'sorting_foreign'),
        );
    }

    #[Test]
    public function theRelationFieldOfTheParentCountsItsChildren(): void
    {
        $this->seed();

        $this->assertSame(
            [['uid' => 21, 'tx_testsinlinerelations_items' => 2]],
            $this->rows('tt_content', 'uid', 'tx_testsinlinerelations_items'),
        );
    }

    /**
     * A child may have children of its own, and nothing about the second level
     * differs from the first: the same declared-id list, resolved against the
     * TCA of the field of the *item* table.
     */
    #[Test]
    public function anInlineChildCarriesInlineChildrenOfItsOwn(): void
    {
        $this->seed();

        $this->assertSame(
            [
                ['uid' => 41, 'parentid' => 31, 'parenttable' => 'tx_testsinlinerelations_item', 'sorting_foreign' => 1],
                ['uid' => 42, 'parentid' => 31, 'parenttable' => 'tx_testsinlinerelations_item', 'sorting_foreign' => 2],
            ],
            $this->rows('tx_testsinlinerelations_link', 'uid', 'parentid', 'parenttable', 'sorting_foreign'),
        );
    }

    /**
     * The children live on the page the scenario put them under, exactly like
     * the content element they hang on: the relation ties them to the parent
     * record, not to its page.
     */
    #[Test]
    public function theChildrenAreWrittenOnThePageTheScenarioDeclaresThemUnder(): void
    {
        $this->seed();

        $this->assertSame(
            [['uid' => 31, 'pid' => 1], ['uid' => 32, 'pid' => 1]],
            $this->rows('tx_testsinlinerelations_item', 'uid', 'pid'),
        );
    }

    #[Test]
    public function theChildrenAreReportedWithTheUidsTheyDeclare(): void
    {
        $result = $this->seed();

        $this->assertSame(31, $result->writtenUid('tx_testsinlinerelations_item', 31));
        $this->assertSame(41, $result->writtenUid('tx_testsinlinerelations_link', 41));
        $this->assertSame(
            [
                'pages' => 1,
                'tt_content' => 1,
                'tx_testsinlinerelations_item' => 2,
                'tx_testsinlinerelations_link' => 2,
            ],
            $result->recordCounts,
        );
    }
}
