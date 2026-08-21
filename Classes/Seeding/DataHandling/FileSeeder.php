<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\DataHandling;

use SBUERK\Seeder\Seeding\Definition\SeedDefinition;
use SBUERK\Seeder\Seeding\Definition\SeedFile;
use SBUERK\Seeder\Seeding\Exception\InvalidSeedDefinitionException;
use SBUERK\Seeder\Seeding\Exception\SeedingFailedException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Copies the files a seed definition brings into a file storage and returns the
 * `sys_file` uid each one was indexed under.
 *
 * The copy goes through the storage API rather than through the filesystem,
 * because that is what *indexes* the file: a file copied into `fileadmin/` with
 * `copy()` exists on disk and does not exist for TYPO3, so nothing can
 * reference it - and nothing says why.
 *
 * Three details of the copy are worth naming, each of them easy to get wrong
 * and none of them loud about it:
 *
 * - **`ResourceStorage::addFile()` moves by default.** Its `$removeOriginal`
 *   argument defaults to `true` (12.4: ResourceStorage.php:1261, 13.4:
 *   ResourceStorage.php:1312), which would delete the source out of the
 *   package shipping the seed. It is passed as `false` here, and a functional
 *   test asserts that the source survived.
 * - **The conflict mode is core version aware**, which is why the call itself
 *   is not in this class. TYPO3 v13 introduced the native enum
 *   {@see \TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior} (#101151) and
 *   answers the older class of the same name with a deprecation this test
 *   suite turns into a failure - while TYPO3 v12 has only that older class and
 *   does not have the enum at all. `addFile()` is therefore called by
 *   {@see FileImporterInterface}, implemented once per core version, and this
 *   class states the *operation* it wants: add the file, replace what is there.
 * - **Permission evaluation is suspended around the copy.** A storage
 *   evaluates the file mounts of the backend user, which is meaningless while
 *   seeding: that runs on the command line, into a folder no user has been
 *   given a mount for, and the storage otherwise refuses with "You are not
 *   allowed to access the given folder". The flag is restored in a `finally`,
 *   because the storage is shared and the next caller is entitled to the
 *   checks.
 *
 * @internal Part of the seeding implementation, not public API.
 */
final class FileSeeder
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly FileImporterInterface $fileImporter,
    ) {}

    /**
     * @return array<string, int> The `sys_file` uid of every file of the
     *         definition, keyed by its seed identifier. That map is what a
     *         declared file reference is resolved against.
     * @throws InvalidSeedDefinitionException
     * @throws SeedingFailedException
     */
    public function seed(SeedDefinition $definition): array
    {
        $uids = [];
        foreach ($definition->files as $file) {
            $source = $this->resolveSource($definition, $file);
            $storage = $this->resolveStorage($definition, $file);

            $evaluatePermissions = $storage->getEvaluatePermissions();
            $storage->setEvaluatePermissions(false);
            try {
                // Inside the suspension as well: creating the target folder
                // evaluates the same permissions the copy does.
                $folder = $this->resolveFolder($storage, $file->folder);
                $addedFile = $this->fileImporter->addFileReplacingExisting(
                    $storage,
                    $source,
                    $folder,
                    $file->name ?? basename($source),
                );
            } finally {
                $storage->setEvaluatePermissions($evaluatePermissions);
            }

            $uids[$file->identifier] = $addedFile->getUid();
        }

        return $uids;
    }

    /**
     * Resolves the declared source to an absolute path.
     *
     * An `EXT:` path is resolved by the core; everything else is taken as
     * relative to the directory the definition was read from, which is what
     * lets a set be moved or renamed without touching its paths.
     *
     * `GeneralUtility::getFileAbsFileName()` is deliberately *not* used for the
     * relative form. It prepends the public web folder to a relative path
     * (13.4/14.3: GeneralUtility.php, `getFileAbsFileName()`), and a seed set
     * lives in an extension rather than below the document root - so the path
     * it built would name a file that is never there. The absolute form is left
     * to it likewise only for `EXT:`, because it answers an absolute path
     * outside the project path with an empty string, and a set can perfectly
     * well sit outside it: a path repository during development, and a test
     * fixture parsed with a base path of its own.
     *
     * @throws InvalidSeedDefinitionException
     */
    private function resolveSource(SeedDefinition $definition, SeedFile $file): string
    {
        $source = PathUtility::isExtensionPath($file->source)
            ? GeneralUtility::getFileAbsFileName($file->source)
            : $this->resolveRelativeSource($definition, $file);

        if ($source === '' || !is_file($source)) {
            throw new InvalidSeedDefinitionException(
                sprintf(
                    'The file "%s" of the seed definition "%s" declares the source "%s", which does not exist%s.',
                    $file->identifier,
                    $definition->identifier,
                    $file->source,
                    $source === '' ? '' : sprintf(' at "%s"', $source),
                ),
                1787076001,
            );
        }

        return $source;
    }

    private function resolveRelativeSource(SeedDefinition $definition, SeedFile $file): string
    {
        if (PathUtility::isAbsolutePath($file->source)) {
            return $file->source;
        }
        if ($definition->basePath === '') {
            // A definition parsed from an array rather than from a file has no
            // directory to resolve against, so a relative source cannot mean
            // anything. Returning nothing lets the message above name the
            // declared path, which is the only thing there is to say about it.
            return '';
        }

        return $definition->basePath . '/' . ltrim($file->source, '/');
    }

    /**
     * @throws SeedingFailedException
     */
    private function resolveStorage(SeedDefinition $definition, SeedFile $file): ResourceStorage
    {
        $storage = $file->storage !== null
            ? $this->storageRepository->findByUid($file->storage)
            : $this->storageRepository->getDefaultStorage();

        if (!$storage instanceof ResourceStorage) {
            // Not an invalid definition: the same definition writes without a
            // word on an instance that has the storage, which is why the
            // message says how to get one rather than what to change in the
            // definition.
            //
            // The default storage is missing less often than one would expect,
            // and never because the instance is new: `findAll()` creates the
            // `fileadmin/` storage itself when the table is empty and flags it
            // as the default (13.4: StorageRepository.php:141ff, 14.3:
            // StorageRepository.php:133ff). What is left is an instance that
            // has storages of which none is the default one - so the message
            // names both ways out.
            throw new SeedingFailedException(
                $file->storage !== null
                    ? sprintf(
                        'The file "%s" of the seed definition "%s" declares the file storage %d, which this'
                        . ' instance does not have.',
                        $file->identifier,
                        $definition->identifier,
                        $file->storage,
                    )
                    : sprintf(
                        'No default file storage is available for the file "%s" of the seed definition "%s". A'
                        . ' TYPO3 instance gets its default storage from "typo3 setup", and an existing storage'
                        . ' counts as the default one only while it is flagged as such; a file may also name the'
                        . ' storage to write into, with "storage: <uid>".',
                        $file->identifier,
                        $definition->identifier,
                    ),
                1787076002,
            );
        }

        return $storage;
    }

    private function resolveFolder(ResourceStorage $storage, string $folder): Folder
    {
        $folder = trim($folder, '/');
        if ($folder === '') {
            return $storage->getRootLevelFolder();
        }

        return $storage->hasFolder($folder)
            ? $storage->getFolder($folder)
            : $storage->createFolder($folder);
    }
}
