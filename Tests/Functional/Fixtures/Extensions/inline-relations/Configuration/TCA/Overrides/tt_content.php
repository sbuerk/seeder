<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// The content element the inline relation hangs on. A content element rather
// than a table of its own, because that is the shape a seed definition
// expresses with "inline": a record below a page, carrying children that are
// tied to it by a relation instead of by their "pid".
ExtensionManagementUtility::addTCAcolumns('tt_content', [
    'tx_testsinlinerelations_items' => [
        'label' => 'Items',
        'config' => [
            'type' => 'inline',
            'foreign_table' => 'tx_testsinlinerelations_item',
            // The three columns DataHandler writes on the *child*. Nothing in
            // the seeder names them: it writes the parent's field as the comma
            // separated list of the children's placeholders and leaves the
            // resolution to DataHandler, which reads them from here.
            'foreign_field' => 'parentid',
            'foreign_table_field' => 'parenttable',
            'foreign_sortby' => 'sorting_foreign',
        ],
    ],
]);

ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'label' => 'Inline relations: item list',
        'value' => 'tests_inline_relations_itemlist',
    ],
);

$GLOBALS['TCA']['tt_content']['types']['tests_inline_relations_itemlist'] = [
    'showitem' => 'CType, header, tx_testsinlinerelations_items',
];
