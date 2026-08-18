<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Functional\Seeding\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\DataHandling\DataMapFactory;
use SBUERK\Seeder\Seeding\DataHandling\FileSeeder;
use SBUERK\Seeder\Seeding\DataHandling\RecordSeeder;
use SBUERK\Seeder\Seeding\Exception\InvalidSeedDefinitionException;
use SBUERK\Seeder\Seeding\Exception\SeedingFailedException;
use SBUERK\Seeder\Seeding\Parser\SeedDefinitionParser;
use SBUERK\Seeder\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Copies the files of a seed definition into a storage and attaches them to the
 * records that declare them.
 *
 * Two mechanisms are under test, and both are silent when they break:
 *
 * - the copy goes through the storage API, so the file is *indexed* - a file
 *   copied into `fileadmin/` with `copy()` exists on disk and does not exist
 *   for TYPO3;
 * - the references are written in a second pass, so `uid_foreign` carries the
 *   uid of a record that exists rather than an unresolved `NEW…` placeholder,
 *   and the parent's counter field is written so `sorting_foreign` is numbered
 *   rather than left at `0`.
 *
 * Every row is read back through the `QueryBuilder`. Hand written SQL would
 * pass here and fail on PostgreSQL, which folds an unquoted identifier to lower
 * case - `SELECT CType` asks for a column `ctype` that does not exist.
 */
final class FileSeedingTest extends AbstractFunctionalTestCase
{
    /**
     * The set is read from disk rather than built in the test, because a
     * `source` relative to the directory holding the set has nothing to resolve
     * against otherwise.
     */
    private const SEED = 'EXT:seeder/Tests/Functional/Fixtures/Seeds/FileSeeding.yaml';

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(dirname(__DIR__, 2) . '/Fixtures/Database/BackendUsers.csv');
    }

    /**
     * The subject, constructed rather than fetched from the container: both
     * services are private and nothing references them yet, so Symfony removes
     * them while compiling the container. Their wiring is proven where they are
     * first injected rather than by publishing them for a test.
     */
    private function subject(): RecordSeeder
    {
        return new RecordSeeder(
            new DataMapFactory(),
            new FileSeeder(GeneralUtility::makeInstance(StorageRepository::class)),
        );
    }

    /**
     * A functional instance has no file storage: the testing framework creates
     * the `fileadmin/` folder but no `sys_file_storage` record, which a real
     * instance gets from `typo3 setup`.
     *
     * Created through the API rather than from a CSV fixture, so the flexform
     * driver configuration and the capability flags stay the core's business
     * instead of a hand written record that is wrong in a way nothing reports.
     *
     * Explicitly, although `StorageRepository` would create a `fileadmin/`
     * storage by itself as soon as it is asked for one and finds the table
     * empty (13.4: StorageRepository.php:141ff, 14.3:
     * StorageRepository.php:133ff). Leaning on that would make every test here
     * depend on a fallback meant for a fresh installation, and would say
     * nothing about an instance that was set up.
     */
    private function createDefaultStorage(): void
    {
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin/', 'relative', 'Seeding test storage', true);
    }

    /**
     * @return array<string, int> The written uids, keyed by seed identifier.
     */
    private function seedFileSet(): array
    {
        $this->createDefaultStorage();

        return $this->subject()->seed(
            (new SeedDefinitionParser())->parseFile(self::SEED),
            $this->setUpBackendUser(1),
        );
    }

    /**
     * Reads a table without restrictions, so what is asserted is what the
     * seeder wrote rather than what happens to be visible.
     *
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    private function queryTable(string $table, array $columns, string $orderBy): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder
            ->select(...$columns)
            ->from($table)
            ->orderBy($orderBy)
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function references(): array
    {
        return $this->queryTable(
            'sys_file_reference',
            [
                'uid',
                'pid',
                'uid_local',
                'uid_foreign',
                'tablenames',
                'fieldname',
                'sorting_foreign',
                'alternative',
                'title',
                'description',
            ],
            'uid',
        );
    }

    /**
     * The `sys_file` identifier of every indexed file, keyed by its uid.
     *
     * @return array<int, string>
     */
    private function indexedFiles(): array
    {
        $files = [];
        foreach ($this->queryTable('sys_file', ['uid', 'identifier'], 'uid') as $row) {
            $files[(int)$row['uid']] = (string)$row['identifier'];
        }

        return $files;
    }

    /**
     * Both accepted source forms end up in the storage, indexed: a path
     * relative to the directory holding the set, and an `EXT:` path.
     *
     * The `sys_file` rows are what proves the copy went through the storage
     * API. A file put into `fileadmin/` with `copy()` would sit on disk exactly
     * the same way and be invisible to TYPO3 - nothing could reference it, and
     * nothing would say why.
     */
    #[Test]
    public function bothSourceFormsAreCopiedIntoTheStorageAndIndexed(): void
    {
        $this->seedFileSet();

        $files = $this->queryTable('sys_file', ['uid', 'identifier', 'name'], 'uid');

        $this->assertSame(
            ['/seed-files/placeholder.svg', '/seed-files/placeholder-portrait.svg'],
            array_map('strval', array_column($files, 'identifier')),
        );
        // The declared folder was created on the way, rather than the files
        // landing in the storage root.
        $this->assertFileExists($this->instancePath . '/fileadmin/seed-files/placeholder.svg');
        $this->assertFileExists($this->instancePath . '/fileadmin/seed-files/placeholder-portrait.svg');
    }

    /**
     * `ResourceStorage::addFile()` *moves* by default - its `$removeOriginal`
     * argument defaults to `true` - which would delete the file out of the
     * package shipping the seed, once, silently, and only on the machine that
     * ran the seeder.
     */
    #[Test]
    public function theSourceFilesStayWhereTheDefinitionDeclaresThem(): void
    {
        $this->seedFileSet();

        $sources = dirname(__DIR__, 2) . '/Fixtures/Seeds/Files';

        $this->assertFileExists($sources . '/placeholder.svg');
        $this->assertFileExists($sources . '/placeholder-portrait.svg');
    }

    /**
     * Every reference belongs to the record that declares it, sits on that
     * record's page, and names the table and the field it was declared under.
     *
     * The assertion that nothing points at record `0` is the one this second
     * pass exists for: `uid_foreign` is a plain integer column, so a `NEW…`
     * placeholder written there is read as `0` - and DataHandler does not log a
     * word about it.
     */
    #[Test]
    public function everyReferencePointsAtTheRecordThatDeclaresIt(): void
    {
        $uids = $this->seedFileSet();

        $references = $this->references();

        $this->assertCount(4, $references, 'Not every declared file reference was written.');
        $this->assertSame([], array_values(array_filter(
            $references,
            static fn(array $row): bool => (int)$row['uid_foreign'] === 0,
        )));

        $onPage = array_values(array_filter(
            $references,
            static fn(array $row): bool => $row['tablenames'] === 'pages',
        ));
        $this->assertCount(1, $onPage);
        $this->assertSame('media', $onPage[0]['fieldname']);
        $this->assertSame($uids['home'], (int)$onPage[0]['uid_foreign']);
        // The page of the level the record sits on, which for a top level page
        // is the page tree root the seed was written below - never the record's
        // own pid, which may be the negative "insert after" hint.
        $this->assertSame(0, (int)$onPage[0]['pid']);

        $onContent = array_values(array_filter(
            $references,
            static fn(array $row): bool => $row['tablenames'] === 'tt_content',
        ));
        $this->assertCount(3, $onContent);
        foreach ($onContent as $row) {
            $this->assertSame('image', $row['fieldname']);
            $this->assertSame($uids['home'], (int)$row['pid']);
        }
    }

    /**
     * A multi file relation comes out in the order the definition declares it.
     *
     * The rows themselves were always written and the images always appeared,
     * which is exactly why this went unnoticed for so long:
     * `FileRepository::findByRelation()` selects by
     * `uid_foreign`/`tablenames`/`fieldname` and never reads the parent's
     * counter column. It *orders* by `sorting_foreign` though, and that column
     * is written by `RelationHandler::writeForeignField()` - which only runs
     * when the parent's field carries the comma separated placeholder list.
     * Without it every seeded reference keeps a `sorting_foreign` of `0` and
     * the order of a gallery is whatever the database feels like returning.
     */
    #[Test]
    public function aMultiFileRelationIsNumberedInDeclarationOrder(): void
    {
        $uids = $this->seedFileSet();

        $files = $this->indexedFiles();
        $gallery = array_values(array_filter(
            $this->references(),
            static fn(array $row): bool => $row['tablenames'] === 'tt_content'
                && (int)$row['uid_foreign'] === $uids['gallery'],
        ));

        $this->assertCount(2, $gallery, 'The two gallery references were not written.');
        // Numbered rather than left at zero...
        $this->assertSame([1, 2], array_map('intval', array_column($gallery, 'sorting_foreign')));
        // ...and numbered in the order the definition declares.
        $this->assertSame('/seed-files/placeholder.svg', $files[(int)$gallery[0]['uid_local']]);
        $this->assertSame('/seed-files/placeholder-portrait.svg', $files[(int)$gallery[1]['uid_local']]);
    }

    /**
     * The counter field of the parent is the other half DataHandler needs, and
     * the honest tell that the relation was understood rather than that rows
     * merely exist: nothing renders it, so it is only ever right on purpose.
     */
    #[Test]
    public function theCounterFieldOfEveryParentCountsItsReferences(): void
    {
        $uids = $this->seedFileSet();

        $content = array_column($this->queryTable('tt_content', ['uid', 'image'], 'uid'), 'image', 'uid');
        $pages = array_column($this->queryTable('pages', ['uid', 'media'], 'uid'), 'media', 'uid');

        $this->assertSame(1, (int)$content[$uids['single']]);
        $this->assertSame(2, (int)$content[$uids['gallery']]);
        // An element declaring no file keeps a counter of zero, which is what
        // makes the two above mean something.
        $this->assertSame(0, (int)$content[$uids['without-image']]);
        $this->assertSame(1, (int)$pages[$uids['home']]);
    }

    /**
     * The fields an editor fills in on a file relation live on the reference
     * rather than on the file, which is what lets the same image carry a
     * different alternative text in two places - and the short form writes none
     * of them, because a bare identifier declares nothing.
     */
    #[Test]
    public function theLongFormWritesTheFieldsOfTheReferenceAndTheShortFormNone(): void
    {
        $uids = $this->seedFileSet();

        $references = $this->references();

        $onPage = array_values(array_filter(
            $references,
            static fn(array $row): bool => $row['tablenames'] === 'pages',
        ))[0];
        $this->assertSame('A placeholder graphic', $onPage['alternative']);
        $this->assertSame('Placeholder', $onPage['title']);
        $this->assertSame('', (string)$onPage['description']);

        $onSingle = array_values(array_filter(
            $references,
            static fn(array $row): bool => (int)$row['uid_foreign'] === $uids['single']
                && $row['tablenames'] === 'tt_content',
        ))[0];
        $this->assertSame('A placeholder graphic in landscape format', $onSingle['alternative']);
        $this->assertSame('The description of a file reference is the caption', $onSingle['description']);

        // Declared as bare identifiers, so nothing but the structural columns
        // is written on them.
        $gallery = array_values(array_filter(
            $references,
            static fn(array $row): bool => (int)$row['uid_foreign'] === $uids['gallery'],
        ));
        $this->assertCount(2, $gallery);
        foreach ($gallery as $row) {
            $this->assertSame('', (string)$row['alternative']);
            $this->assertSame('', (string)$row['title']);
            $this->assertSame('', (string)$row['description']);
        }
    }

    /**
     * The columns the seeder owns win over a declared value, so a definition
     * cannot detach a reference from the record carrying it - the same rule a
     * record's `pid` follows. Everything else is written as declared.
     */
    #[Test]
    public function aReferenceCannotBeDetachedFromTheRecordThatDeclaresIt(): void
    {
        $this->createDefaultStorage();

        $definition = (new SeedDefinitionParser())->parse(
            [
                'identifier' => 'structural',
                'title' => 'Structural columns win',
                'files' => [
                    ['identifier' => 'placeholder', 'source' => 'Files/placeholder.svg', 'folder' => 'structural'],
                ],
                'pages' => [
                    [
                        'identifier' => 'home',
                        'uid' => 1,
                        'title' => 'Home',
                        'files' => [
                            'media' => [
                                [
                                    'identifier' => 'placeholder',
                                    // The columns the seeder owns...
                                    'uid_foreign' => 999,
                                    'tablenames' => 'tt_content',
                                    'fieldname' => 'image',
                                    'pid' => 42,
                                    // ...and one it does not.
                                    'alternative' => 'Kept',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'structural definition',
            dirname(__DIR__, 2) . '/Fixtures/Seeds',
        );

        $this->subject()->seed($definition, $this->setUpBackendUser(1));

        $references = $this->references();

        $this->assertCount(1, $references);
        $this->assertSame(1, (int)$references[0]['uid_foreign']);
        $this->assertSame('pages', $references[0]['tablenames']);
        $this->assertSame('media', $references[0]['fieldname']);
        $this->assertSame(0, (int)$references[0]['pid']);
        $this->assertSame('Kept', $references[0]['alternative']);
    }

    /**
     * A declared source that is not there names the file and the path it was
     * looked for at, because that is the difference between a typo in the
     * definition and a file that was not shipped.
     */
    #[Test]
    public function aMissingSourceFileIsRefusedWithTheIdentifierAndThePath(): void
    {
        $this->createDefaultStorage();

        $definition = (new SeedDefinitionParser())->parse(
            [
                'identifier' => 'missing-file',
                'title' => 'A source that is not there',
                'files' => [
                    ['identifier' => 'absent', 'source' => 'Files/absent.svg'],
                ],
                'pages' => [
                    ['identifier' => 'home', 'title' => 'Home'],
                ],
            ],
            'missing file definition',
            dirname(__DIR__, 2) . '/Fixtures/Seeds',
        );

        try {
            $this->subject()->seed($definition, $this->setUpBackendUser(1));
            $this->fail('A seed file with a missing source was not refused.');
        } catch (InvalidSeedDefinitionException $exception) {
            $this->assertSame(1787076001, $exception->getCode());
            $this->assertStringContainsString('"absent"', $exception->getMessage());
            $this->assertStringContainsString('Files/absent.svg', $exception->getMessage());
        }

        // Refused before anything was written, so a broken definition leaves no
        // half seeded page tree behind.
        $this->assertSame([], $this->queryTable('pages', ['uid'], 'uid'));
    }

    /**
     * An instance without a default storage is refused with a message saying
     * how to get one, rather than with a null dereference somewhere in the
     * storage API.
     *
     * This is deliberately not an invalid definition: the same definition
     * writes without a word on the next instance.
     *
     * A storage is created here rather than none, and that is the whole point
     * of the test: `StorageRepository` creates a `fileadmin/` storage itself
     * when the table is empty and flags it as the default one (13.4:
     * StorageRepository.php:141ff, 14.3: StorageRepository.php:133ff), so an
     * instance *without* any storage cannot reach this failure at all. What
     * reaches it is an instance whose storages exist and of which none is the
     * default - the state a hand made storage or an import leaves behind.
     */
    #[Test]
    public function anInstanceWithoutADefaultStorageIsRefused(): void
    {
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('secondary', 'fileadmin/', 'relative', 'Not the default storage', false);

        $definition = (new SeedDefinitionParser())->parseFile(self::SEED);

        try {
            $this->subject()->seed($definition, $this->setUpBackendUser(1));
            $this->fail('Seeding files without a default storage was not refused.');
        } catch (SeedingFailedException $exception) {
            $this->assertSame(1787076002, $exception->getCode());
            $this->assertStringContainsString('typo3 setup', $exception->getMessage());
            $this->assertStringContainsString('storage: <uid>', $exception->getMessage());
        }

        $this->assertSame([], $this->queryTable('pages', ['uid'], 'uid'));
    }

    /**
     * A record referencing a file the definition does not declare is a broken
     * definition, and the message names both.
     */
    #[Test]
    public function referencingAFileTheDefinitionDoesNotDeclareIsRefused(): void
    {
        $this->createDefaultStorage();

        $definition = (new SeedDefinitionParser())->parse([
            'identifier' => 'undeclared',
            'title' => 'An undeclared file',
            'pages' => [
                ['identifier' => 'home', 'title' => 'Home', 'files' => ['media' => ['nope']]],
            ],
        ]);

        try {
            $this->subject()->seed($definition, $this->setUpBackendUser(1));
            $this->fail('A reference to an undeclared file was not refused.');
        } catch (InvalidSeedDefinitionException $exception) {
            $this->assertSame(1787076003, $exception->getCode());
            $this->assertStringContainsString('"home"', $exception->getMessage());
            $this->assertStringContainsString('"nope"', $exception->getMessage());
        }

        $this->assertSame([], $this->queryTable('pages', ['uid'], 'uid'));
    }
}
