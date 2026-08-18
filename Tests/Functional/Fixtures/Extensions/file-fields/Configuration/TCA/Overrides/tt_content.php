<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// A content element with a file field of its own, so the file seeding tests do
// not have to borrow a CType and a column from "fluid_styled_content" or from
// the shipped "tt_content" TCA — both of which are free to change what they
// offer between core versions, and neither of which is the subject of a test
// about seeding.
ExtensionManagementUtility::addTCAcolumns('tt_content', [
    'tx_testsfilefields_media' => [
        'label' => 'Media',
        'config' => [
            'type' => 'file',
            'allowed' => 'common-image-types',
        ],
    ],
]);

ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'label' => 'File fields: teaser',
        'value' => 'tests_file_fields_teaser',
    ],
);

$GLOBALS['TCA']['tt_content']['types']['tests_file_fields_teaser'] = [
    'showitem' => 'CType, header, tx_testsfilefields_media',
];
