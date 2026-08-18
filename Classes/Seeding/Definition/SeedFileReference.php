<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\Definition;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One file reference a record declares: which seeded file it points at, and the
 * fields written on the `sys_file_reference` record itself.
 *
 * Those fields are the ones an editor fills in on a file relation in the
 * backend - `alternative`, `title`, `description`, `link`, `crop` - and they
 * live on the reference rather than on the file, which is what lets the same
 * image carry a different alternative text in two places.
 *
 * This is data, not a service.
 *
 * @internal Part of the seeding implementation, not public API.
 */
#[Exclude]
final readonly class SeedFileReference
{
    /**
     * @param array<string, scalar|null> $values Fields of the
     *        `sys_file_reference` record. The columns the seeder sets itself -
     *        `uid_local`, `uid_foreign`, `tablenames`, `fieldname` and `pid` -
     *        are structural and always win over a declared value.
     */
    public function __construct(
        public string $identifier,
        public array $values = [],
    ) {}
}
