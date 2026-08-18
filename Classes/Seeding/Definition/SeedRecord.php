<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\Definition;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One record of a seed definition, with the records nested below it.
 *
 * A record is described by the table it belongs to, a symbolic identifier
 * unique within the definition, the field values to write, and optionally the
 * uid it should be given. Nesting expresses the page tree: the children of a
 * page are written with that page as their parent, whatever table they belong
 * to, which is what lets a page carry both sub pages and content.
 *
 * This is data, not a service. It is created by the parser and never fetched
 * from the container.
 *
 * @internal This is part of the seeding implementation of this extension and
 *           not public API. It is deliberately kept free of dependencies on the
 *           rest of the extension so it can be extracted into a package of its
 *           own later.
 */
#[Exclude]
final readonly class SeedRecord
{
    /**
     * @param array<string, scalar|null> $values
     * @param list<SeedRecord> $children
     * @param array<string, list<SeedFileReference>> $files File references to
     *        create, as a map of field name to the references declared for it.
     * @param array<string, list<SeedRecord>> $inline Inline children, as a map
     *        of the parent field carrying the relation to the records declared
     *        for it. Unlike `$children` these are not nested by `pid` but by a
     *        relation, so the field name is what ties them to the parent.
     */
    public function __construct(
        public string $table,
        public string $identifier,
        public array $values,
        public ?int $uid = null,
        public array $children = [],
        public array $files = [],
        public array $inline = [],
    ) {}

    /**
     * The placeholder DataHandler is given for this record. A record with a
     * declared uid keeps a stable placeholder as well, because the uid is only
     * a *suggestion* to DataHandler and the placeholder is what the data map is
     * keyed by either way.
     *
     * **The placeholder carries no underscore, and that is not cosmetic.** A
     * placeholder used as the value of a relation field goes through
     * `DataHandler::processRemapStack()`, which reads a value containing an
     * underscore as the `<table>_<uid>` form the backend writes for a group
     * field, and splits it there:
     *
     * ```php
     * if (!str_contains($value, '_')) {
     *     $affectedTable = $tcaFieldConf['foreign_table'] ?? '';
     *     $prependTable = false;
     * } else {
     *     $parts = explode('_', $value);
     *     $value = array_pop($parts);
     *     $affectedTable = implode('_', $parts);
     *     $prependTable = true;
     * }
     * $value = $this->substNEWwithIDs[$value] ?? '';
     * ```
     *
     * `NEWtt_content_home` is therefore taken apart into the table
     * `NEWtt_content` and the id `home`, `substNEWwithIDs['home']` does not
     * exist, and `?? ''` writes the relation **empty - with an empty error
     * log**, so nothing reports it. The table name has its underscores removed
     * and is joined to the identifier with a dash for that reason, and
     * `SeedDefinitionParser` rejects an identifier that would reintroduce one.
     *
     * The quoted branch is unchanged between TYPO3 v13.4 and v14.3. It is
     * found by searching `DataHandler::processRemapStack()` for the comment
     * "Replace relations to NEW...-IDs in field value", which is the line above
     * it - a search that keeps working when the line numbers move
     * (13.4: DataHandler.php:7169, 14.3: DataHandler.php:7173).
     */
    public function placeholder(): string
    {
        return 'NEW' . str_replace('_', '', $this->table) . '-' . $this->identifier;
    }
}
