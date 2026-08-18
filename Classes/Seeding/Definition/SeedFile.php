<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\Definition;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A file a seed definition copies into a file storage before it writes records.
 *
 * This is data, not a service.
 *
 * @internal Part of the seeding implementation, not public API.
 */
#[Exclude]
final readonly class SeedFile
{
    /**
     * @param string $identifier Unique among the files of the definition. It is
     *        what a record's file reference names.
     * @param string $source Where the file comes from: a path relative to the
     *        directory holding the set - {@see SeedDefinition::$basePath} - or
     *        an `EXT:` path.
     * @param string $folder Target folder inside the storage, defaulting to its
     *        root.
     * @param string|null $name Target file name, defaulting to the basename of
     *        the source.
     * @param int|null $storage Target storage, defaulting to the default
     *        storage of the instance.
     */
    public function __construct(
        public string $identifier,
        public string $source,
        public string $folder = '/',
        public ?string $name = null,
        public ?int $storage = null,
    ) {}
}
