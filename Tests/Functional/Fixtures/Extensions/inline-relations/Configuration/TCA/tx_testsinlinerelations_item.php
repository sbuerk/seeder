<?php

declare(strict_types=1);

/**
 * The child table of the inline relation this fixture provides.
 *
 * It exists so that the seeder's inline handling can be proven against a real
 * relation rather than against a data map: the columns tying a child to its
 * parent are written by DataHandler through
 * \TYPO3\CMS\Core\Database\RelationHandler::writeForeignField(), and which
 * columns those are comes from the TCA of the *parent* field — never from the
 * child.
 *
 * The names mirror the ones core uses on "sys_file_reference", so a test
 * asserting "sorting_foreign" is asserting the same convention twice rather
 * than a name invented here: "parentid" is the "foreign_field", "parenttable"
 * the "foreign_table_field" and "sorting_foreign" the "foreign_sortby" of
 * "tt_content.tx_testsinlinerelations_items".
 *
 * The table carries a file field and an inline relation of its own, because an
 * inline child of a seed may declare "files" and further "inline" children —
 * and nothing proves that a level deeper than the first is handled at all
 * unless there is one.
 *
 * On TYPO3 v13 every column here is derived from the TCA by
 * \TYPO3\CMS\Core\Database\Schema\DefaultTcaSchema, the two "passthrough"
 * relation columns included — that class adds the "foreign_field" and the
 * "foreign_table_field" of an inline relation to the child table itself. TYPO3
 * v12 derives the "ctrl" fields only and never creates a table no
 * "ext_tables.sql" declares, which is why this fixture ships one; see the
 * comment at the top of that file.
 *
 * @todo Drop the "ext_tables.sql" once TYPO3 v12 support is dropped.
 */
return [
    'ctrl' => [
        'title' => 'Inline relations: item',
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
        'image' => [
            'label' => 'Image',
            'config' => [
                'type' => 'file',
                'allowed' => 'common-image-types',
            ],
        ],
        'links' => [
            'label' => 'Links',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_testsinlinerelations_link',
                'foreign_field' => 'parentid',
                'foreign_table_field' => 'parenttable',
                'foreign_sortby' => 'sorting_foreign',
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'hidden, title, image, links',
        ],
    ],
];
