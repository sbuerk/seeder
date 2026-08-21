<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Seeding\DataHandling;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * What one scenario import wrote.
 *
 * The uid map is keyed `<table>:<declaredUid>` rather than by a symbolic name,
 * because a scenario record has none: the `id` an entity declares *is* its
 * handle, and it is the uid the record is written with. The map exists at all
 * because that is only true while the uid is free - `--force` gives up the
 * suggestion for a colliding table, and then a record ends up under whatever
 * uid the database assigned.
 *
 * This is data, not a service.
 *
 * @internal Part of the seeding implementation, not public API.
 */
#[Exclude]
final class ScenarioSeedResult
{
    /**
     * @param array<string, int> $writtenUids `<table>:<declaredUid>` mapped to
     *        the uid the record was actually written with.
     * @param array<string, int> $recordCounts Number of records written, per
     *        table, in the order the tables first occur in the scenario.
     * @param array<string, int> $fileUids File identifier of the set mapped to
     *        the `sys_file` uid it was indexed under.
     * @param list<int> $referenceUids The `sys_file_reference` uid of every
     *        reference the set declared, in declared order.
     */
    public function __construct(
        public readonly array $writtenUids = [],
        public readonly array $recordCounts = [],
        public readonly array $fileUids = [],
        public readonly array $referenceUids = [],
    ) {}

    /**
     * The same result with the file references filled in.
     *
     * They are written in a pass after the records, and that pass is handed
     * this object to resolve the records and the files it points at - so the
     * result exists before the last thing it reports does.
     *
     * @param list<int> $referenceUids
     */
    public function withReferenceUids(array $referenceUids): self
    {
        return new self($this->writtenUids, $this->recordCounts, $this->fileUids, $referenceUids);
    }

    /**
     * The uid a record declared as `<table>:<declaredUid>` was written with, or
     * null when the scenario never declared it.
     */
    public function writtenUid(string $table, int $declaredUid): ?int
    {
        return $this->writtenUids[$table . ':' . $declaredUid] ?? null;
    }

    /**
     * Every page uid the run wrote, which is what the check for a site root
     * without a site configuration walks.
     *
     * @return list<int>
     */
    public function pageUids(): array
    {
        $uids = [];
        foreach ($this->writtenUids as $key => $uid) {
            if (str_starts_with($key, 'pages:')) {
                $uids[] = $uid;
            }
        }

        return $uids;
    }

    public function recordCount(): int
    {
        return array_sum($this->recordCounts);
    }
}
