<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Seeding\Definition;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One file reference a seed set declares: which seeded file is attached to
 * which field of which record, and what is written on the
 * `sys_file_reference` row itself.
 *
 * It lives in `config.yml` rather than in a scenario file, and that is the
 * whole reason this class exists. The shape a scenario *can* express - a
 * parent field carrying the declared uids of its children - needs the child's
 * uid to be declarable, and a `sys_file_reference` needs `uid_local`: the uid
 * the FAL indexer assigns while the file is being placed, which a set author
 * cannot write down. So the file, and everything pointing at it, is declared
 * where this extension's own configuration lives.
 *
 * `$table`/`$uid` name the record by the uid the **scenario declares**,
 * resolved against what the run actually wrote - the same rule
 * {@see SeedSiteConfiguration::$rootPage} follows, and for the same reason: a
 * scenario record has no symbolic identifier.
 *
 * This is data, not a service.
 *
 * @internal Part of the seeding implementation, not public API.
 */
#[Exclude]
final readonly class SeedFileReference
{
    /**
     * @param string $file Identifier of a file declared under `files`.
     * @param string $table Table of the record the reference hangs on.
     * @param int $uid Uid the scenario declares for that record.
     * @param string $field The `type => 'file'` column of that record.
     * @param array<string, scalar|null> $values Fields of the
     *        `sys_file_reference` row - the ones an editor fills in on a file
     *        relation: `title`, `alternative`, `description`, `link`, `crop`.
     *        The structural columns `uid_local`, `uid_foreign`, `tablenames`,
     *        `fieldname` and `pid` are written by the seeder and win over a
     *        declared value, because a definition may not detach a reference
     *        from the record it declares it on.
     */
    public function __construct(
        public string $file,
        public string $table,
        public int $uid,
        public string $field,
        public array $values = [],
    ) {}
}
