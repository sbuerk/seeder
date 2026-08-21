<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Core12\Seeding\DataHandling;

use SBUERK\Seeder\Seeding\DataHandling\FileImporterInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use TYPO3\CMS\Core\Resource\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;

/**
 * TYPO3 v12 implementation of {@see FileImporterInterface}.
 *
 * The difference to the v13 implementation is the `use` statement above and
 * nothing else: on TYPO3 v12 the conflict mode is
 * `TYPO3\CMS\Core\Resource\DuplicationBehavior`, a `Core\Type\Enumeration`
 * subclass whose `REPLACE` is a plain string class constant. The native enum
 * `…\Resource\Enum\DuplicationBehavior` the v13 implementation uses does not
 * exist in `typo3/cms-core` 12.4, so naming it in this file would be a fatal
 * error on the only core version this file is ever loaded for.
 *
 * `ResourceStorage::addFile()` normalises the argument with
 * `DuplicationBehavior::cast()` on v12 (12.4: ResourceStorage.php:1268) and
 * raises no deprecation for it - the deprecation is a v13 addition. See
 * {@see FileImporterInterface} for the full reasoning behind the split.
 *
 * Only the `Core12/` directory is registered in the dependency injection
 * container when running on TYPO3 v12, see `Configuration/Services.php`.
 * `#[AsAlias]` makes this class the default implementation of the interface, so
 * consumers type hint the interface and receive the implementation matching the
 * running TYPO3 version. The alias is public because the functional test
 * fetches it from the container to assert that wiring.
 *
 * The class is plain `final`. Readonly classes are PHP 8.2, and this branch
 * supports PHP 8.1 for TYPO3 v12 - there is nothing to declare `readonly` here
 * anyway, because the service is stateless and has no dependencies. See
 * `docs/architecture/class-design.md`.
 *
 * @todo Remove together with {@see FileImporterInterface} when TYPO3 v12
 *       support is dropped.
 *
 * @internal Part of the seeding implementation, not public API.
 */
#[AsAlias(id: FileImporterInterface::class, public: true)]
final class FileImporter implements FileImporterInterface
{
    public function addFileReplacingExisting(
        ResourceStorage $storage,
        string $localFilePath,
        Folder $targetFolder,
        string $targetFileName,
    ): File {
        // TYPO3 v12 documents `addFile()` as returning `FileInterface`, which
        // is wider than what the method can produce: it ends in
        // `getFileByIdentifier()`, and that returns a `ProcessedFile` only for
        // an identifier inside the processing folder - which a seeded file
        // never is - and a `File` from `createIndexEntry()` or
        // `getFileObject()` in every other case. TYPO3 v13 corrected the
        // docblock to `@return File` (13.4: ResourceStorage.php:1309), so the
        // annotation states the v13 truth rather than a guess. It is needed
        // because `FileInterface` carries no `getUid()`, which is the single
        // thing the seeder wants back.
        /** @var File $file */
        $file = $storage->addFile(
            $localFilePath,
            $targetFolder,
            $targetFileName,
            DuplicationBehavior::REPLACE,
            false,
        );

        return $file;
    }
}
