<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Tests\Functional\Seeding\DataHandling;

use PHPUnit\Framework\Attributes\Test;
use SBUERK\Seeder\Seeding\DataHandling\FileImporterInterface;
use SBUERK\Seeder\Seeding\DataHandling\FileSeeder;
use SBUERK\Seeder\Seeding\Definition\SeedDefinition;
use SBUERK\Seeder\Seeding\Definition\SeedFile;
use SBUERK\Seeder\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Covers the core version aware seam every seeded file goes through.
 *
 * `ResourceStorage::addFile()` takes its conflict mode as a class constant on
 * TYPO3 v12 and as a native enum on v13 (#101151), so the call is split into
 * `Core12/Seeding/DataHandling/FileImporter` and its `Core13/` counterpart
 * behind {@see FileImporterInterface}. What that split promises is a single
 * behaviour: seeding the same target name twice replaces the file instead of
 * adding a second one - which is what makes a seed set repeatable.
 *
 * The tests carry **no** PHPUnit group on purpose. A group would restrict them
 * to the version they were written on, and the whole point of a version split
 * is that both implementations keep the same promise. What differs per version
 * is the class name the container hands out, and that is asserted by computing
 * it from `Typo3Version` rather than by having two test classes.
 *
 * {@see FileSeedingTest} covers what the seeder does around the call - path
 * resolution, the target folder, the refusals, and that the source file is left
 * where the set declares it. This class covers only what the split is about,
 * which is why none of that is repeated here.
 */
final class FileImporterTest extends AbstractFunctionalTestCase
{
    /**
     * Two sources with different contents, so "the second run replaced the
     * first" can be asserted on the bytes rather than only on the row.
     */
    private const SOURCE_LANDSCAPE = 'EXT:seeder/Tests/Functional/Fixtures/Seeds/Files/placeholder.svg';
    private const SOURCE_PORTRAIT = 'EXT:seeder/Tests/Functional/Fixtures/Seeds/Files/placeholder-portrait.svg';

    private const TARGET_FOLDER = 'importer-test';
    private const TARGET_NAME = 'placeholder.svg';

    /**
     * The test instance is created once for the whole test case and only the
     * database is reset between tests, so a file an earlier test copied into
     * the storage is still on disk while its `sys_file` row is gone. Both
     * assertions below count rows *and* files, so the storage is wiped rather
     * than the assertions weakened.
     */
    protected function setUp(): void
    {
        parent::setUp();

        GeneralUtility::rmdir($this->instancePath . '/fileadmin', true);
        GeneralUtility::mkdir($this->instancePath . '/fileadmin');

        // A functional instance has no file storage: the testing framework
        // creates the "fileadmin/" folder but no "sys_file_storage" record,
        // which a real instance gets from "typo3 setup".
        GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('fileadmin', 'fileadmin/', 'relative', 'File importer test storage', true);
    }

    #[Test]
    public function registeredImplementationMatchesTheRunningCoreVersion(): void
    {
        $importer = $this->get(FileImporterInterface::class);

        $this->assertInstanceOf(FileImporterInterface::class, $importer);
        $this->assertSame(
            sprintf(
                'SBUERK\\Seeder\\Core%d\\Seeding\\DataHandling\\FileImporter',
                (new Typo3Version())->getMajorVersion(),
            ),
            $importer::class,
            'The container registered the file importer of a different core version than the one running. '
            . 'Only the "Core<major>/" directory of the running version is loaded, see "Configuration/Services.php".',
        );
    }

    #[Test]
    public function seedingTheSameTargetNameTwiceReplacesTheFile(): void
    {
        $first = $this->seed(self::SOURCE_LANDSCAPE);
        $second = $this->seed(self::SOURCE_PORTRAIT);

        // The same "sys_file" uid comes back, so the second run updated the
        // index entry of the existing file rather than indexing a second one.
        // With the "rename" conflict mode this is a new uid, with "cancel" the
        // second call throws before it gets here.
        $this->assertSame(
            $first['placeholder'],
            $second['placeholder'],
            'Seeding the same file twice produced two "sys_file" records.',
        );

        $this->assertSame(
            ['/' . self::TARGET_FOLDER . '/' . self::TARGET_NAME],
            $this->indexedFileIdentifiers(),
            'The storage holds more than the one file that was seeded twice - the second run renamed instead '
            . 'of replacing.',
        );
    }

    #[Test]
    public function seedingTheSameTargetNameTwiceOverwritesTheContent(): void
    {
        $this->seed(self::SOURCE_LANDSCAPE);
        $this->seed(self::SOURCE_PORTRAIT);

        // Reusing the row is only half of "replace": the bytes on disk have to
        // be the ones of the second source, otherwise a seed set could never
        // correct a file it shipped earlier.
        $this->assertSame(
            (string)file_get_contents(GeneralUtility::getFileAbsFileName(self::SOURCE_PORTRAIT)),
            $this->storage()->getFile('/' . self::TARGET_FOLDER . '/' . self::TARGET_NAME)->getContents(),
        );
    }

    /**
     * Seeds a single file under the fixed target name, through the real
     * {@see FileSeeder} and the container's importer.
     *
     * The seeder is constructed rather than fetched, because it is not the
     * subject here - the importer it delegates to is, and that one has to come
     * from the container to be the implementation of the running core version.
     *
     * The sources are `EXT:` paths, so the definition needs no `basePath` and
     * nothing has to be read from disk to build it.
     *
     * @return array<string, int>
     */
    private function seed(string $source): array
    {
        $seeder = new FileSeeder(
            GeneralUtility::makeInstance(StorageRepository::class),
            $this->get(FileImporterInterface::class),
        );

        return $seeder->seed(new SeedDefinition(
            identifier: 'file-importer',
            title: 'One file, seeded twice',
            files: [
                new SeedFile(
                    identifier: 'placeholder',
                    source: $source,
                    folder: self::TARGET_FOLDER,
                    name: self::TARGET_NAME,
                ),
            ],
        ));
    }

    private function storage(): ResourceStorage
    {
        $storage = GeneralUtility::makeInstance(StorageRepository::class)->getDefaultStorage();
        $this->assertInstanceOf(ResourceStorage::class, $storage);

        return $storage;
    }

    /**
     * Read back with the `QueryBuilder` rather than with hand written SQL,
     * which would pass here and fail on PostgreSQL.
     *
     * @return list<string>
     */
    private function indexedFileIdentifiers(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();

        /** @var list<string> $identifiers */
        $identifiers = $queryBuilder
            ->select('identifier')
            ->from('sys_file')
            ->orderBy('uid')
            ->executeQuery()
            ->fetchFirstColumn();

        return $identifiers;
    }
}
