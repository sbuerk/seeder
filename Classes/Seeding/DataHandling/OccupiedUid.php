<?php

declare(strict_types=1);

namespace SBUERK\DataFactory\Seeding\DataHandling;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One uid a seed set suggests and which the installation already uses.
 *
 * It carries the title because a refusal naming "pages:1, pages:2" tells
 * nobody whether that is a page tree worth keeping. "pages:1 (Company site)"
 * does.
 *
 * This is data, not a service.
 *
 * @internal Part of the seeding implementation, not public API.
 */
#[Exclude]
final readonly class OccupiedUid
{
    /**
     * @param string $table The table the uid is occupied in. A uid collides per
     *        table and never across tables, which is the whole reason the check
     *        exists in this shape.
     * @param int $uid The occupied uid, as the set suggests it.
     * @param string $title The label of the record occupying it, taken from the
     *        label field the TCA of the table declares. Empty when the table
     *        declares none, or when the record's label is empty - both of which
     *        are normal and neither of which is worth a placeholder text.
     */
    public function __construct(
        public string $table,
        public int $uid,
        public string $title,
    ) {}
}
