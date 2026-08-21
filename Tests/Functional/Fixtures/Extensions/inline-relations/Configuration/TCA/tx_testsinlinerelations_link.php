<?php

declare(strict_types=1);

/**
 * The grandchild table of this fixture: the inline children of an inline
 * child.
 *
 * It carries the same three relation columns as
 * "tx_testsinlinerelations_item", and for the same reason — they are named by
 * the TCA of "tx_testsinlinerelations_item.links", which is the parent field
 * here. What makes them worth a table of their own is "parenttable": a level
 * deeper the value written there is the *child* table rather than
 * "tt_content", so a test can tell the two levels apart instead of trusting
 * that a nested child was written at all.
 *
 * As for the item table, TYPO3 v13 derives every column from this TCA while
 * v12 needs the table and its non-"ctrl" columns spelled out — see
 * "ext_tables.sql" of this fixture.
 *
 * @todo Drop the "ext_tables.sql" once TYPO3 v12 support is dropped.
 */
return [
    'ctrl' => [
        'title' => 'Inline relations: link',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        // Required rather than decorative: an inline child of a workspace
        // aware table has to be workspace aware itself, and TYPO3 v14 migrates
        // a table that is not - with a deprecation, which this suite turns
        // into a failure. "tt_content" is workspace aware, so the item table
        // is, and the link table below it in turn.
        'versioningWS' => true,
        'hideTable' => true,
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        // Without this a record of this table may not be written onto a
        // standard page at all: DataHandler asks PageDoktypeRegistry, whose
        // default doktype allows "pages,sys_category,sys_file_reference,
        // sys_file_collection" and everything that declares this flag - and
        // refuses the rest with "Attempt to insert record on pages:1 where
        // table ... is not allowed". Every core table an editor puts on a page,
        // "tt_content" included, declares it, so a fixture table which does not
        // would be testing an installation nobody has.
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
            ],
        ],
        'parentid' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'parenttable' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        // Not "passthrough" like the two columns above, although nothing but
        // RelationHandler ever writes it: DefaultTcaSchema derives the
        // "foreign_field" and the "foreign_table_field" of an inline relation
        // by itself, but never the "foreign_sortby" — a passthrough column is
        // not derived at all, and on TYPO3 v13 the relation would be numbered
        // into a column that does not exist. Declared as core declares
        // "sys_file_reference.sorting_foreign", for the same reason.
        'sorting_foreign' => [
            'label' => 'Sorting',
            'config' => [
                'type' => 'number',
                'size' => 4,
                'default' => 0,
            ],
        ],
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'hidden, title',
        ],
    ],
];
