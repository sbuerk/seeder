<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\Definition;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A parsed seed definition: what the set is called, the files it brings, the
 * records it writes in the order they were declared, and the site
 * configurations it sets up afterwards.
 *
 * This is data, not a service.
 *
 * @internal Part of the seeding implementation, not public API.
 */
#[Exclude]
final readonly class SeedDefinition
{
    /**
     * @param string $identifier Globally unique across all packages. It is
     *        declared, never derived from the directory holding the set: a
     *        derived identifier turns a collision between two packages into a
     *        silent one.
     * @param string $title Shown by `seeder:list`.
     * @param string $description Optional long text, empty when the definition
     *        declares none.
     * @param string $basePath Absolute path of the directory holding the entry
     *        file, without a trailing slash, and an empty string for a
     *        definition parsed from an array rather than from a file. Every
     *        relative resource path of the set - a file `source`, a site
     *        `template` - resolves against it, which is what lets a set be
     *        moved or renamed without touching its paths. `EXT:` paths are
     *        absolute in that sense and ignore it.
     * @param list<SeedRecord> $records The top level records of the set, which
     *        are the pages of its page tree.
     * @param list<SeedFile> $files Files copied into a storage before the
     *        records are written, so records can reference them.
     * @param list<SeedSiteConfiguration> $sites Site configurations written
     *        after the records, when their root page has a uid.
     */
    public function __construct(
        public string $identifier,
        public string $title,
        public string $description = '',
        public string $basePath = '',
        public array $records = [],
        public array $files = [],
        public array $sites = [],
    ) {}
}
