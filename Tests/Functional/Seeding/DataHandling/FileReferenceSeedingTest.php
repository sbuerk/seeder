<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Tests\Functional\Seeding\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\DataFactory\Seeding\DataHandling\FileReferenceSeeder;
use SBUERK\DataFactory\Seeding\DataHandling\FileSeeder;
use SBUERK\DataFactory\Seeding\DataHandling\ScenarioSeeder;
use SBUERK\DataFactory\Seeding\DataHandling\ScenarioSeedResult;
use SBUERK\DataFactory\Seeding\Definition\SeedDefinition;
use SBUERK\DataFactory\Seeding\Definition\SeedFile;
use SBUERK\DataFactory\Seeding\Definition\SeedFileReference;
use SBUERK\DataFactory\Seeding\Exception\SeedingFailedException;
use SBUERK\DataFactory\Seeding\Parser\SeedDefinitionParser;
use SBUERK\DataFactory\Seeding\Scenario\ScenarioComposer;
use SBUERK\DataFactory\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Attaches the files of a seed set to the records it wrote.
 *
 * This is the one feature the scenario format has no concept of, and the tests
 * here are about the two ways it fails silently rather than about the happy
 * path alone:
 *
 * - **`uid_foreign` is a plain integer column.** A `NEW…` placeholder written
 *   there stays a string, is read as `0`, and the reference belongs to record
 *   0 - with an empty error log. That is why the references are a pass of
 *   their own, after the records.
 * - **The field of the parent has to be written too.** Without it
 *   `RelationHandler::writeForeignField()` never runs and every reference
 *   keeps a `sorting_foreign` of `0`, which nothing notices until a multi file
 *   relation comes out in an arbitrary order.
 *
 * Every row is read back through the `QueryBuilder`. Hand written SQL would
 * pass here and fail on PostgreSQL, which folds an unquoted identifier to lower
 * case - `SELECT CType` asks for a column `ctype` that does not exist.
 */
final class FileReferenceSeedingTest extends AbstractFunctionalTestCase
{
    /**
     * The set is read from disk rather than built in the test: `references` is
     * a key of `config.yml`, and what is under test is a set declaring it the
     * way an extension ships it.
     */
    private const SEED = 'EXT:data_factory/Tests/Functional/Fixtures/Seeds/FileReferenceSeeding.yaml';

    protected array $testExtensionsToLoad = [
        'sbuerk/data-factory',
        __DIR__ . '/../../Fixtures/Extensions/file-fields',
    ];

    /**
     * The test instance is created once for the whole test case and only the
     * database is reset between tests, so a file an earlier test copied into
     * the storage is still on disk while its `sys_file` row is gone. The
     * storage is wiped for the same reason {@see FileSeedingTest} wipes it.
     */
    protected function setUp(): void
    {
        parent::setUp();

        GeneralUtility::rmdir($this->instancePath . '/fileadmin', true);
        GeneralUtility::mkdir($this->instancePath . '/fileadmin');
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin/', 'relative', 'Seeding test storage', true);

        $this->importCSVDataSet(dirname(__DIR__, 2) . '/Fixtures/Database/BackendUsers.csv');
    }

    private function subject(): ScenarioSeeder
    {
        return new ScenarioSeeder(
            new FileSeeder(GeneralUtility::makeInstance(StorageRepository::class)),
            new FileReferenceSeeder(GeneralUtility::makeInstance(ConnectionPool::class)),
        );
    }

    private function seed(?SeedDefinition $definition = null): ScenarioSeedResult
    {
        $definition ??= (new SeedDefinitionParser())->parseFile(self::SEED);

        return $this->subject()->seed(
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
    public function aDeclaredReferenceCarriesTheStructuralColumnsOfTheRelation(): void
    {
        $this->seed();

        $this->assertSame(
            [
                ['uid_local' => 1, 'uid_foreign' => 21, 'tablenames' => 'tt_content', 'fieldname' => 'tx_testsfilefields_media'],
                ['uid_local' => 2, 'uid_foreign' => 21, 'tablenames' => 'tt_content', 'fieldname' => 'tx_testsfilefields_media'],
                ['uid_local' => 1, 'uid_foreign' => 1, 'tablenames' => 'pages', 'fieldname' => 'media'],
            ],
            $this->rows('sys_file_reference', 'uid_local', 'uid_foreign', 'tablenames', 'fieldname'),
        );
    }

    #[Test]
    public function theDeclaredValuesAreWrittenOnTheReferenceItself(): void
    {
        $this->seed();

        $rows = $this->rows('sys_file_reference', 'uid', 'title', 'alternative');

        $this->assertSame(
            ['uid' => 1, 'title' => 'Landscape', 'alternative' => 'The wide one'],
            $rows[0],
        );
    }

    #[Test]
    public function severalReferencesOnOneFieldAreNumberedInDeclaredOrder(): void
    {
        $this->seed();

        $this->assertSame(
            [
                ['uid' => 1, 'uid_local' => 1, 'sorting_foreign' => 1],
                ['uid' => 2, 'uid_local' => 2, 'sorting_foreign' => 2],
                ['uid' => 3, 'uid_local' => 1, 'sorting_foreign' => 1],
            ],
            $this->rows('sys_file_reference', 'uid', 'uid_local', 'sorting_foreign'),
        );
    }

    #[Test]
    public function theRelationFieldOfTheParentCountsItsReferences(): void
    {
        $this->seed();

        $this->assertSame(
            [['uid' => 21, 'tx_testsfilefields_media' => 2]],
            $this->rows('tt_content', 'uid', 'tx_testsfilefields_media'),
        );
    }

    /**
     * An inline child belongs to the page its parent is on, and for a parent
     * that *is* a page that means the page itself - not the page above it,
     * which for a site root is `0` and would put the reference outside the
     * tree.
     */
    #[Test]
    public function aReferenceOfAPageLivesOnThatPage(): void
    {
        $this->seed();

        $rows = $this->rows('sys_file_reference', 'uid', 'pid');

        $this->assertSame(
            [
                ['uid' => 1, 'pid' => 1],
                ['uid' => 2, 'pid' => 1],
                ['uid' => 3, 'pid' => 1],
            ],
            $rows,
        );
    }

    /**
     * The point of writing through `DataHandler` rather than inserting the
     * rows: the reference index is what the v13 frontend resolves a file
     * relation with, and a raw insert leaves it empty without a word.
     */
    #[Test]
    public function theReferenceIndexKnowsTheSeededRelations(): void
    {
        $this->seed();

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_refindex');
        $count = $queryBuilder
            ->count('hash')
            ->from('sys_refindex')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter('sys_file_reference')),
                $queryBuilder->expr()->eq('ref_table', $queryBuilder->createNamedParameter('sys_file')),
            )
            ->executeQuery()
            ->fetchOne();

        $this->assertSame(3, (int)$count);
    }

    #[Test]
    public function theResultReportsTheUidOfEveryReferenceInDeclaredOrder(): void
    {
        $this->assertSame([1, 2, 3], $this->seed()->referenceUids);
    }

    #[Test]
    public function aSetWithoutReferencesWritesNone(): void
    {
        $definition = (new SeedDefinitionParser())->parseFile(
            'EXT:data_factory/Tests/Functional/Fixtures/Seeds/FileSeeding.yaml',
        );

        $result = $this->seed($definition);

        $this->assertSame([], $result->referenceUids);
        $this->assertSame([], $this->rows('sys_file_reference', 'uid'));
    }

    #[Test]
    public function aReferenceOnARecordTheScenarioDoesNotDeclareIsRefused(): void
    {
        $parsed = (new SeedDefinitionParser())->parseFile(self::SEED);
        $definition = new SeedDefinition(
            identifier: $parsed->identifier,
            title: $parsed->title,
            basePath: $parsed->basePath,
            scenarios: $parsed->scenarios,
            files: $parsed->files,
            references: [new SeedFileReference('landscape', 'tt_content', 999, 'tx_testsfilefields_media')],
        );

        $this->expectException(SeedingFailedException::class);
        $this->expectExceptionCode(1787256502);

        $this->seed($definition);
    }

    /**
     * Unreachable through `config.yml`, which the parser refuses. Reachable
     * through a definition built in code, and the alternative to refusing is a
     * reference written with a `uid_local` of `0`.
     */
    #[Test]
    public function aReferenceToAFileTheSetDoesNotBringIsRefused(): void
    {
        $parsed = (new SeedDefinitionParser())->parseFile(self::SEED);
        $definition = new SeedDefinition(
            identifier: $parsed->identifier,
            title: $parsed->title,
            basePath: $parsed->basePath,
            scenarios: $parsed->scenarios,
            files: [new SeedFile('landscape', 'Files/placeholder.svg', 'seed-files')],
            references: [new SeedFileReference('portrait', 'tt_content', 21, 'tx_testsfilefields_media')],
        );

        $this->expectException(SeedingFailedException::class);
        $this->expectExceptionCode(1787256501);

        $this->seed($definition);
    }
}
