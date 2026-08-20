<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\Definition;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A parsed seed set descriptor: what the set is called, which scenario files
 * describe its records, the files it brings, where those files are referenced
 * from, and the site configurations it sets up afterwards.
 *
 * The records themselves are **not** in here. They live in the scenario files
 * this names, in the YAML scenario format of `typo3/testing-framework`, and are
 * read by {@see \SBUERK\Seeder\Seeding\Scenario\ScenarioComposer} rather than
 * by the parser that produced this object. `config.yml` describes the set; a
 * scenario file describes the data.
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
     *        relative resource path of the set - a scenario file, a file
     *        `source`, a site `template` - resolves against it, which is what
     *        lets a set be moved or renamed without touching its paths. `EXT:`
     *        paths are absolute in that sense and ignore it.
     * @param list<string> $scenarios The scenario files of the set **in
     *        declared order**, as they were declared. They are composed into
     *        one scenario, not imported one after another, so the order is the
     *        order records are written in and the order later settings override
     *        earlier ones in.
     * @param list<SeedFile> $files Files copied into a storage before the
     *        records are written.
     * @param list<SeedFileReference> $references File references attached to
     *        the seeded records once their uids are known, in a pass of their
     *        own. They are declared here rather than in a scenario file
     *        because a `sys_file_reference` needs the `sys_file` uid the FAL
     *        indexer assigns, which nothing can write down in advance.
     * @param list<SeedSiteConfiguration> $sites Site configurations written
     *        after the records, when their root page has a uid.
     */
    public function __construct(
        public string $identifier,
        public string $title,
        public string $description = '',
        public string $basePath = '',
        public array $scenarios = [],
        public array $files = [],
        public array $references = [],
        public array $sites = [],
    ) {}
}
