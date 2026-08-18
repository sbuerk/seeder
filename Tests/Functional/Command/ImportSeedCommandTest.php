<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Command\ImportSeedCommand;
use SBUERK\Seeder\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * What `seeder:import` writes, and what it refuses to write.
 *
 * The command is taken from the container rather than constructed: half of what
 * is under test is the wiring - `#[AsCommand]` reaching the `console.command`
 * tag, and the whole seeding pipeline being injectable without a service
 * definition anywhere - and this is the place where every service of it is
 * first injected.
 *
 * Every assertion reads the database back through the `QueryBuilder`. Hand
 * written SQL would pass on SQLite and MySQL and fail on PostgreSQL, which
 * folds an unquoted identifier to lower case - `SELECT CType` asks for a column
 * `ctype` that does not exist.
 */
final class ImportSeedCommandTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'sbuerk/seeder',
        'tests/seeds-demo',
        'tests/seeds-import',
        'tests/file-fields',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(dirname(__DIR__) . '/Fixtures/Database/BackendUsers.csv');
        // The instance directory outlives a single test while the database is
        // truncated between them, so a file a previous test copied would still
        // be there - and the dry run asserts that no file was copied.
        GeneralUtility::rmdir($this->seededFileFolder(), true);
        // A functional instance has no file storage: the testing framework
        // creates the "fileadmin/" folder but no "sys_file_storage" record,
        // which a real instance gets from "typo3 setup".
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin/', 'relative', 'Seeding test storage', true);
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function theCommandIsRegisteredUnderItsName(): void
    {
        $this->assertTrue($this->get(CommandRegistry::class)->has('seeder:import'));
    }

    #[Test]
    public function aSeedSetIsWrittenWithTheUidsItDeclares(): void
    {
        $commandTester = $this->execute();

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertSame(
            [
                ['uid' => 11, 'pid' => 0, 'title' => 'Import home', 'slug' => '/'],
                ['uid' => 12, 'pid' => 11, 'title' => 'About', 'slug' => '/about'],
            ],
            $this->pages(),
        );
        $content = $this->rows('tt_content', ['uid', 'pid', 'CType', 'header']);
        $this->assertCount(2, $content);
        $this->assertSame(21, (int)$content[0]['uid']);
        $this->assertSame('Imported content', $content[0]['header']);
    }

    #[Test]
    public function theFilesOfTheSetAreCopiedAndReferenced(): void
    {
        $this->assertSame(Command::SUCCESS, $this->execute()->getStatusCode());

        $this->assertFileExists($this->seededFileFolder() . '/placeholder.svg');
        $this->assertCount(1, $this->rows('sys_file', ['uid']));
        $references = $this->rows('sys_file_reference', ['uid', 'uid_foreign', 'tablenames', 'fieldname']);
        $this->assertCount(1, $references);
        $this->assertSame('tt_content', $references[0]['tablenames']);
        $this->assertSame('tx_testsfilefields_media', $references[0]['fieldname']);
    }

    #[Test]
    public function theSiteConfigurationOfTheSetIsWrittenWithTheSeededRootPage(): void
    {
        $this->assertSame(Command::SUCCESS, $this->execute()->getStatusCode());

        $configuration = $this->writtenSiteConfiguration('import-main');
        // The template names 999, and the declared root page always wins.
        $this->assertSame(11, $configuration['rootPageId']);
        $this->assertSame('https://import.example.org/', $configuration['base']);
    }

    #[Test]
    public function theSummaryNamesTheRecordsPerTableTheFilesAndTheSites(): void
    {
        $display = $this->normalize($this->execute()->getDisplay());

        $this->assertStringContainsString('Table Records', $display);
        $this->assertStringContainsString('pages 2', $display);
        $this->assertStringContainsString('tt_content 2', $display);
        $this->assertStringContainsString('Written below page 0 (the page tree root)', $display);
        $this->assertStringContainsString('Files indexed: 1', $display);
        $this->assertStringContainsString('Site configurations written: import-main', $display);
        $this->assertStringContainsString('Imported "import-full"', $display);
        // The identifier-to-uid table is what "-v" adds, and nothing else does.
        $this->assertStringNotContainsString('Seed identifier', $display);
        // The set declares the site of its root page, so there is nothing to
        // warn about - the assertion below is what keeps the warning of
        // "noSiteConfigIsSkippedAndTheUncoveredSiteRootIsReported" meaningful.
        $this->assertStringNotContainsString('No site configuration covers', $display);
    }

    #[Test]
    public function raisedVerbosityAddsTheIdentifierToUidTable(): void
    {
        $display = $this->normalize($this->execute(verbosity: OutputInterface::VERBOSITY_VERBOSE)->getDisplay());

        $this->assertStringContainsString('Seed identifier Uid', $display);
        $this->assertStringContainsString('home 11', $display);
        $this->assertStringContainsString('about 12', $display);
        $this->assertStringContainsString('home-heading 21', $display);
    }

    #[Test]
    public function aDryRunWritesNoRecordNoFileAndNoSiteConfiguration(): void
    {
        $commandTester = $this->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertSame([], $this->pages());
        $this->assertSame([], $this->rows('tt_content', ['uid']));
        $this->assertSame([], $this->rows('sys_file', ['uid']));
        $this->assertSame([], $this->rows('sys_file_reference', ['uid']));
        $this->assertDirectoryDoesNotExist($this->seededFileFolder());
        $this->assertDirectoryDoesNotExist(Environment::getConfigPath() . '/sites/import-main');

        $display = $this->normalize($commandTester->getDisplay());
        $this->assertStringContainsString('Dry run: nothing is written.', $display);
        $this->assertStringContainsString('pages 2', $display);
        $this->assertStringContainsString('Would be written below page 0', $display);
        $this->assertStringContainsString('Files to index: 1', $display);
        $this->assertStringContainsString('Site configurations to write: import-main', $display);
        $this->assertStringContainsString('no file, no record and no site configuration', $display);
    }

    #[Test]
    public function aDryRunListsTheUidsTheSetWouldSuggestWhenVerbose(): void
    {
        $display = $this->normalize(
            $this->execute(['--dry-run' => true], OutputInterface::VERBOSITY_VERBOSE)->getDisplay(),
        );

        $this->assertStringContainsString('Seed identifier Table Suggested uid', $display);
        $this->assertStringContainsString('home pages 11', $display);
        $this->assertStringContainsString('home-image tt_content assigned by DataHandler', $display);
    }

    #[Test]
    public function theBaseOptionReplacesTheBaseOfEverySiteConfigurationTheSetWrites(): void
    {
        $commandTester = $this->execute(['--base' => 'https://instance.example.com/']);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertSame(
            'https://instance.example.com/',
            $this->writtenSiteConfiguration('import-main')['base'],
        );
    }

    #[Test]
    public function noSiteConfigIsSkippedAndTheUncoveredSiteRootIsReported(): void
    {
        $commandTester = $this->execute(['--no-site-config' => true]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        // The records are written, only the site configurations are not.
        $this->assertCount(2, $this->pages());
        $this->assertDirectoryDoesNotExist(Environment::getConfigPath() . '/sites/import-main');
        $this->assertSame([], $this->automaticSiteConfigurations());

        $display = $this->normalize($commandTester->getDisplay());
        $this->assertStringContainsString('Site configurations written: 0', $display);
        $this->assertStringContainsString('No site configuration covers "home" (page 11)', $display);
    }

    /**
     * A set that declares a site root and no site configuration is the case the
     * warning exists for: seeding suppresses the "autogenerated-<uid>" site
     * TYPO3 writes by itself, so the tree is there and no frontend can render
     * it - and nothing else would say so.
     */
    #[Test]
    public function aSeededSiteRootThatNoSiteConfigurationCoversIsReported(): void
    {
        $commandTester = $this->execute(identifier: 'demo-content');

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $this->assertSame([], $this->automaticSiteConfigurations());
        $this->assertStringContainsString(
            'No site configuration covers "home"',
            $this->normalize($commandTester->getDisplay()),
        );
    }

    #[Test]
    public function aSetIsWrittenBelowTheRootPageItIsGiven(): void
    {
        $this->importCSVDataSet(dirname(__DIR__) . '/Fixtures/Database/OccupiedUids.csv');

        $commandTester = $this->execute(['--root-page' => '11'], identifier: 'demo-content');

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $seeded = $this->rows('pages', ['uid', 'pid', 'title'], 'uid');
        $this->assertSame('Home', $seeded[1]['title']);
        $this->assertSame(11, (int)$seeded[1]['pid']);
        $this->assertStringContainsString(
            'Written below page 11',
            $this->normalize($commandTester->getDisplay()),
        );
    }

    #[Test]
    public function aUidTheInstallationAlreadyUsesRefusesTheImportAndNamesIt(): void
    {
        $this->importCSVDataSet(dirname(__DIR__) . '/Fixtures/Database/OccupiedUids.csv');

        $commandTester = $this->execute();

        $this->assertSame(ImportSeedCommand::EXIT_UID_COLLISION, $commandTester->getStatusCode());
        $display = $this->normalize($commandTester->getDisplay());
        $this->assertStringContainsString('suggests 2 uids this installation already uses', $display);
        $this->assertStringContainsString('pages 11 An existing page', $display);
        $this->assertStringContainsString('tt_content 21 An existing element', $display);
        // Nothing of the set was written, including the records that would not
        // have collided.
        $this->assertSame([11], array_map(static fn(array $row): int => (int)$row['uid'], $this->pages()));
        $this->assertCount(1, $this->rows('tt_content', ['uid']));
        $this->assertSame([], $this->rows('sys_file', ['uid']));
        $this->assertDirectoryDoesNotExist(Environment::getConfigPath() . '/sites/import-main');
    }

    /**
     * A deleted record occupies its uid as much as any other row: `uid` is the
     * primary key, and an insert with that uid fails on it.
     */
    #[Test]
    public function aDeletedRecordOccupiesItsUid(): void
    {
        $this->importCSVDataSet(dirname(__DIR__) . '/Fixtures/Database/OccupiedUids.csv');
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['deleted' => 1], ['uid' => 11]);
        $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->update('tt_content', ['deleted' => 1], ['uid' => 21]);

        $commandTester = $this->execute();

        $this->assertSame(ImportSeedCommand::EXIT_UID_COLLISION, $commandTester->getStatusCode());
        $this->assertStringContainsString(
            'pages 11 An existing page',
            $this->normalize($commandTester->getDisplay()),
        );
    }

    #[Test]
    public function theSameSetIsImportedUnderForceWithFreeUids(): void
    {
        $this->importCSVDataSet(dirname(__DIR__) . '/Fixtures/Database/OccupiedUids.csv');

        $commandTester = $this->execute(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $commandTester->getStatusCode());
        $pages = $this->pages();
        $this->assertCount(3, $pages);
        // The record that was in the way is untouched.
        $this->assertSame(['uid' => 11, 'pid' => 0, 'title' => 'An existing page', 'slug' => '/existing'], $pages[0]);
        $home = $this->pageTitled('Import home');
        $about = $this->pageTitled('About');
        $this->assertNotSame(11, $home['uid']);
        $this->assertSame($home['uid'], $about['pid']);

        $display = $this->normalize($commandTester->getDisplay());
        $this->assertStringContainsString('"--force" was given', $display);
        $this->assertStringContainsString('pages:11 is occupied by "An existing page"', $display);
        $this->assertStringContainsString('tt_content:21 is occupied by "An existing element"', $display);
    }

    /**
     * @param array<string, bool|string> $options
     */
    private function execute(
        array $options = [],
        int $verbosity = OutputInterface::VERBOSITY_NORMAL,
        string $identifier = 'import-full',
    ): CommandTester {
        $commandTester = new CommandTester($this->get(ImportSeedCommand::class));
        $commandTester->execute(
            ['identifier' => $identifier] + $options,
            ['interactive' => false, 'verbosity' => $verbosity],
        );

        return $commandTester;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pages(): array
    {
        return $this->rows('pages', ['uid', 'pid', 'title', 'slug']);
    }

    /**
     * @return array<string, int|string>
     */
    private function pageTitled(string $title): array
    {
        foreach ($this->pages() as $row) {
            if ($row['title'] === $title) {
                return ['uid' => (int)$row['uid'], 'pid' => (int)$row['pid']];
            }
        }

        $this->fail(sprintf('No page titled "%s" was written.', $title));
    }

    /**
     * Reads a table without restrictions, so what is asserted is what the
     * command wrote rather than what happens to be visible.
     *
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, array $columns, string $orderBy = 'uid'): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select(...$columns)
            ->from($table)
            ->orderBy($orderBy)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static function (array $row): array {
                foreach (['uid', 'pid', 'uid_foreign'] as $column) {
                    if (isset($row[$column])) {
                        $row[$column] = (int)$row[$column];
                    }
                }

                return $row;
            },
            $rows,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function writtenSiteConfiguration(string $identifier): array
    {
        $file = Environment::getConfigPath() . '/sites/' . $identifier . '/config.yaml';
        $this->assertFileExists($file);

        /** @var array<string, mixed> $configuration */
        $configuration = Yaml::parseFile($file);

        return $configuration;
    }

    /**
     * @return list<string>
     */
    private function automaticSiteConfigurations(): array
    {
        return glob(Environment::getConfigPath() . '/sites/autogenerated-*') ?: [];
    }

    private function seededFileFolder(): string
    {
        return Environment::getPublicPath() . '/fileadmin/seed-import';
    }

    /**
     * Collapses the padding of the tables and the wrapping of the blocks, so
     * that a row can be asserted as the sequence of its cells and a sentence as
     * a sentence.
     */
    private function normalize(string $display): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $display));
    }
}
