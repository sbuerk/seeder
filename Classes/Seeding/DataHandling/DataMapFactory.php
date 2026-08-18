<?php

declare(strict_types=1);

namespace SBUERK\Seeder\Seeding\DataHandling;

use SBUERK\Seeder\Seeding\Definition\SeedDefinition;
use SBUERK\Seeder\Seeding\Definition\SeedRecord;
use SBUERK\Seeder\Seeding\Exception\InvalidSeedDefinitionException;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;

/**
 * Turns a seed definition into the data map `DataHandler` consumes.
 *
 * Four details carry the weight here, and each of them fails silently when it
 * is wrong: the tree comes out reversed, a relation is written empty, a record
 * lands on a record instead of on a page, or a suggested uid is dropped without
 * a word.
 *
 * **Nesting becomes `pid`.** A child is written with the placeholder of its
 * parent as its `pid`, which DataHandler resolves to the real uid once the
 * parent has been created.
 *
 * **Order is preserved through negative pids.** DataHandler puts a new record
 * at the *top* of its parent by default, so records created in the order they
 * are declared would come out reversed. The convention it offers instead is a
 * negative `pid`, meaning "directly after this record" - it strips the sign,
 * resolves the placeholder and hands the signed value to
 * `resolveSortingAndPidForNewRecord()` (13.4: DataHandler.php:740ff,
 * 14.3: DataHandler.php:742ff). Only the first sibling therefore addresses its
 * parent; every following one addresses the sibling before it.
 *
 * That predecessor is tracked **per table**: a negative pid names a record of
 * the same table, and the children of a page are a mix of sub pages, content
 * elements and records of other tables. Pointing a content element at the page
 * before it would place it somewhere else entirely.
 *
 * **An inline child is nested by a relation, not by a pid.** Its `pid` is the
 * page the parent sits on, and the relation is expressed by writing the
 * parent's field as the comma separated list of the children's placeholders.
 * DataHandler resolves those and writes the relation columns of the child
 * itself - which columns those are is per relation and comes from the TCA of
 * the parent field, so nothing here names one. Their order comes from that list
 * and from nothing else, which is why an inline child gets a plain pid rather
 * than the negative "insert after" hint used for a page or a content element.
 *
 * Records are also seeded **visible**. A page created through DataHandler comes
 * out hidden, which is right for an editor and wrong for a seed: the tree would
 * exist, the frontend would render nothing, and nothing would say why.
 *
 * A field needs **no support in this factory to be seedable**. Apart from the
 * structural `pid` and the defaults below, every declared value is copied into
 * the data map untouched. That is a design decision made of the absence of a
 * branch - a factory special-casing a field it does not have to will
 * special-case the next one too - and the tests are what keep it true.
 *
 * The file references a record declares ({@see SeedRecord::$files}) are
 * **collected rather than written**. `sys_file_reference.uid_foreign` is a
 * plain integer column and not a relation DataHandler resolves, so a reference
 * cannot go into the same pass as the record it points at: a `NEW…`
 * placeholder written there stays a string, is read as `0`, and the reference
 * silently belongs to record 0. What leaves this factory is therefore a flat
 * list of what to write once the records exist, which
 * {@see RecordSeeder} turns into a second data map.
 *
 * @phpstan-type SeedFileReferenceRow array{
 *     parent: string,
 *     table: string,
 *     field: string,
 *     file: int,
 *     pid: string,
 *     values: array<string, scalar|null>
 * }
 *
 * @internal Part of the seeding implementation, not public API.
 */
final readonly class DataMapFactory
{
    /**
     * The fields every `pages` row carries whether the definition declared them
     * or not.
     *
     * A seed says what it writes. Without these three the values come from
     * whatever the TCA of the installation happens to declare, so the same
     * definition writes a different page in the next installation - and none of
     * that is visible in the definition.
     *
     * They are these three because they are the ones another piece of core
     * reads back: `DataHandler` runs
     * `TYPO3\CMS\Core\Hooks\CreateSiteConfiguration` after every new record,
     * and that hook reads `$fieldValues['l10n_parent']`, `$fieldValues['pid']`
     * and `$fieldValues['doktype']` out of the assembled field array. The
     * `l10n_parent` read is unguarded on TYPO3 v13.4
     * (CreateSiteConfiguration.php:71, `?? 0` on v14.3), `doktype` is unguarded
     * on both (CreateSiteConfiguration.php:74), and `||` short-circuits, so a
     * seeded page always reaches the `l10n_parent` condition and a seeded root
     * page always reaches the `doktype` one.
     *
     * What arrives without them was established rather than assumed, on 13.4
     * and 14.3:
     *
     * - `doktype` comes from the shipped TCA, which does declare
     *   `'default' => (string)PageRepository::DOKTYPE_DEFAULT` on both versions
     *   - so `DataHandler::newFieldArray()` fills it in (13.4:
     *   DataHandler.php:8244, 14.3: DataHandler.php:8223).
     * - `l10n_parent` is not a column of the shipped `pages` TCA at all. It is
     *   materialised by `TcaEnrichment::enrichTransOrigPointerField()`, which
     *   gives it `'default' => 0` - so it is filled in as well.
     * - `sys_language_uid` is materialised the same way but as
     *   `'type' => 'language'`, and `LanguageFieldType::hasDefaultValue()`
     *   returns `false`. It is therefore *not* filled in, and DataHandler falls
     *   back to `addDefaultPermittedLanguageIfNotSet()`, which takes the first
     *   language the site of the target page offers rather than the default
     *   language.
     *
     * So the hook does not warn today, and this is a guard rather than a fix
     * for an observed warning: an extension redefining `l10n_parent` on `pages`
     * without a default reintroduces exactly that warning on v13.4, and an
     * extension changing the `doktype` default silently changes what every seed
     * writes. The functional test asserts that the hook path is walked, so it
     * goes red when a core version starts reading a field that is not here.
     *
     * A declared value always wins: these are defaults, not overrides. `pid` is
     * the exception and is never taken from the definition, because it is
     * structure rather than a field.
     */
    private const PAGE_DEFAULTS = [
        'doktype' => PageRepository::DOKTYPE_DEFAULT,
        'l10n_parent' => 0,
        'sys_language_uid' => 0,
    ];

    /**
     * @param int $rootPageId The page the top level records of the definition
     *        are written below. `0` is the page tree root.
     * @param array<string, int> $fileUids The `sys_file` uid of every file the
     *        definition brought, keyed by its seed identifier - what
     *        {@see FileSeeder::seed()} returns. A record referencing a file
     *        needs that uid before the reference can be described, which is why
     *        the files are copied before the map is built.
     * @param array<string, true> $withoutSuggestedUids The uids of the
     *        definition that are *not* suggested to DataHandler, keyed
     *        `<table>:<uid>` - the shape of the result's `suggestedUids`, so
     *        the answer of a collision check can be handed straight back in.
     *        Those records are written with the next free uid instead. This is
     *        what `seeder:import --force` uses: leaving the suggestion in for a
     *        uid another row already holds does not write the record with a
     *        different uid, it makes the `INSERT` fail on the primary key -
     *        DataHandler forces the uid into the field array
     *        (`insertDB()`, 13.4: DataHandler.php:7786, 14.3:
     *        DataHandler.php:7761) and the insert is refused by the database.
     *        What that looks like was established by removing the check and
     *        watching it: on 13.4 the failed insert returns `null` and the
     *        `pages` branch of `process_datamap()` hands that `null` on to
     *        `addDefaultPermittedLanguageIfNotSet()`, which raises a
     *        `TypeError` - so it is not even the logged SQL error one would
     *        expect. The `uid` is dropped from the row as well, so the map says
     *        what will happen;
     *        DataHandler unsets it there anyway ("Do NOT insert the UID field,
     *        ever!") and reads the suggestion from this list.
     * @return array{
     *     dataMap: array<string, array<string, array<string, scalar|null>>>,
     *     suggestedUids: array<string, true>,
     *     references: list<SeedFileReferenceRow>
     * }
     * @throws InvalidSeedDefinitionException
     */
    public function createFromDefinition(
        SeedDefinition $definition,
        int $rootPageId = 0,
        array $fileUids = [],
        array $withoutSuggestedUids = [],
    ): array {
        $dataMap = [];
        $suggestedUids = [];
        $references = [];

        $this->collect(
            $definition->records,
            (string)$rootPageId,
            $dataMap,
            $suggestedUids,
            $fileUids,
            $references,
            $withoutSuggestedUids,
        );

        return ['dataMap' => $dataMap, 'suggestedUids' => $suggestedUids, 'references' => $references];
    }

    /**
     * @param list<SeedRecord> $records
     * @param array<string, array<string, array<string, scalar|null>>> $dataMap
     * @param array<string, true> $suggestedUids
     * @param array<string, int> $fileUids
     * @param list<SeedFileReferenceRow> $references
     * @param array<string, true> $withoutSuggestedUids
     * @throws InvalidSeedDefinitionException
     */
    private function collect(
        array $records,
        string $parentId,
        array &$dataMap,
        array &$suggestedUids,
        array $fileUids,
        array &$references,
        array $withoutSuggestedUids,
    ): void {
        /** @var array<string, string> $previousIdPerTable */
        $previousIdPerTable = [];

        foreach ($records as $record) {
            $placeholder = $record->placeholder();
            $previousId = $previousIdPerTable[$record->table] ?? null;
            $pid = $previousId === null ? $parentId : '-' . $previousId;

            $this->write(
                $record,
                $pid,
                $parentId,
                $dataMap,
                $suggestedUids,
                $fileUids,
                $references,
                $withoutSuggestedUids,
            );

            $previousIdPerTable[$record->table] = $placeholder;

            if ($record->children !== []) {
                $this->collect(
                    $record->children,
                    $placeholder,
                    $dataMap,
                    $suggestedUids,
                    $fileUids,
                    $references,
                    $withoutSuggestedUids,
                );
            }
        }
    }

    /**
     * Writes one record into the data map, together with its inline children.
     *
     * @param string $pid The pid to write, which for a sibling after the first
     *        is the negative "insert after" hint.
     * @param string $parentId The page the record sits on. Needed separately
     *        from $pid, because an inline child and a file reference both have
     *        to go onto a page and the negative hint is a sorting instruction
     *        rather than one.
     * @param array<string, array<string, array<string, scalar|null>>> $dataMap
     * @param array<string, true> $suggestedUids
     * @param array<string, int> $fileUids
     * @param list<SeedFileReferenceRow> $references
     * @param array<string, true> $withoutSuggestedUids
     * @throws InvalidSeedDefinitionException
     */
    private function write(
        SeedRecord $record,
        string $pid,
        string $parentId,
        array &$dataMap,
        array &$suggestedUids,
        array $fileUids,
        array &$references,
        array $withoutSuggestedUids,
    ): void {
        $values = $record->values;
        // Structural, so never taken from the definition.
        $values['pid'] = $pid;
        // A page created through DataHandler comes out hidden - verified by
        // dropping this line and watching the functional test find "hidden=1".
        // For a seed that is the wrong way round: the tree exists, the frontend
        // renders nothing and nothing says why. A definition can still ask for
        // a hidden record by declaring "hidden: 1" itself.
        $values += ['hidden' => 0];
        if ($record->table === 'pages') {
            // See PAGE_DEFAULTS: what a page is - its type and its language -
            // comes from the seed rather than from the TCA of the installation
            // it is written into.
            $values += self::PAGE_DEFAULTS;
        }

        foreach ($record->inline as $field => $children) {
            if ($children === []) {
                continue;
            }
            // Declaration order, because that is the order DataHandler numbers
            // the relation by - it walks this list, not the data map.
            $values[$field] = implode(',', array_map(
                static fn(SeedRecord $child): string => $child->placeholder(),
                $children,
            ));
        }

        foreach ($record->files as $field => $fileReferences) {
            foreach ($fileReferences as $fileReference) {
                if (!isset($fileUids[$fileReference->identifier])) {
                    throw new InvalidSeedDefinitionException(
                        sprintf(
                            'The record "%s" references the file "%s", which the seed definition does not declare.',
                            $record->identifier,
                            $fileReference->identifier,
                        ),
                        1787076003,
                    );
                }
                $references[] = [
                    'parent' => $record->placeholder(),
                    'table' => $record->table,
                    'field' => $field,
                    'file' => $fileUids[$fileReference->identifier],
                    // The page of this level, never the record's own pid: that
                    // one may be the negative "insert after" hint, which is a
                    // sorting instruction and not a page.
                    'pid' => $parentId,
                    // The fields of the reference record itself - the
                    // alternative text, the title, the description - which is
                    // where they belong: the same file carries a different
                    // alternative text in two places.
                    'values' => $fileReference->values,
                ];
            }
        }

        if ($record->uid !== null && !isset($withoutSuggestedUids[$record->table . ':' . $record->uid])) {
            // Both halves are required, and neither is obvious.
            //
            // The uid goes into the data map row, because DataHandler reads the
            // suggestion from "$incomingFieldArray['uid']" when it calls
            // "insertDB()" - not from "suggestedInsertUids" (13.4:
            // DataHandler.php:854/858, 14.3: DataHandler.php:857/861). It then
            // drops the column again before the insert - "Do NOT insert the UID
            // field, ever!" (13.4: DataHandler.php:7796, 14.3:
            // DataHandler.php:7771) - so putting it here cannot write a uid by
            // itself.
            //
            // And "suggestedInsertUids" is keyed "<table>:<uid>", not by the
            // placeholder, because that is the key "insertDB()" looks up
            // (13.4: DataHandler.php:7806, 14.3: DataHandler.php:7781). A
            // placeholder key is simply never found.
            //
            // Getting either one wrong fails silently: DataHandler assigns the
            // next free uid, the seed reports whatever it got, and the result
            // is right only as long as declaration order happens to equal
            // insertion order.
            $values['uid'] = $record->uid;
            $suggestedUids[$record->table . ':' . $record->uid] = true;
        }

        $dataMap[$record->table][$record->placeholder()] = $values;

        foreach ($record->inline as $children) {
            foreach ($children as $child) {
                if ($child->children !== []) {
                    // The parser refuses this, and this is the second half of
                    // that rule: an inline child is nested by a relation, its
                    // page-style children are reached by neither this loop nor
                    // collect(), and writing them here would need a pid rule
                    // the format does not define. Dropping them is what used to
                    // happen, and it happened without a word.
                    throw new InvalidSeedDefinitionException(
                        sprintf(
                            'The inline child "%s" of "%s" carries nested records, which cannot be written: "children", "content" and "records" nest onto a page, and an inline child is nested into a relation instead.',
                            $child->identifier,
                            $record->identifier,
                        ),
                        1787078002,
                    );
                }
                // The page the parent sits on, for the child and for anything
                // the child carries in turn.
                $this->write(
                    $child,
                    $parentId,
                    $parentId,
                    $dataMap,
                    $suggestedUids,
                    $fileUids,
                    $references,
                    $withoutSuggestedUids,
                );
            }
        }
    }
}
