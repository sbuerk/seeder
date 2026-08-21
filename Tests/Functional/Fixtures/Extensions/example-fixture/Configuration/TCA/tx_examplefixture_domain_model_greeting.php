<?php

declare(strict_types=1);

/**
 * TCA of the greeting table of the fixture extension.
 *
 * The table name follows the Extbase convention, which derives it from the
 * class name of the model and not from the extension key:
 * "TESTS\ExampleFixture\Domain\Model\Greeting" resolves to
 * "tx_examplefixture_domain_model_greeting" — the vendor part is dropped, the
 * rest is lower cased and joined with underscores. See
 * \TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapFactory::resolveTableName().
 *
 * The table is deliberately both language aware and version aware: it declares
 * the language fields, so records can be translated and are overlaid on
 * retrieval, and "versioningWS", so workspace overlays apply as well.
 *
 * TCA is configuration, not code, so a core version difference cannot be
 * resolved by the "Core12/" and "Core13/" split used for classes. It would be
 * applied to the array before returning it instead, at the bottom of this file
 * - there is no such difference between the two versions this branch supports,
 * and the note at the bottom says what the 2.x line does instead.
 */
$tcaConfiguration = [
    'ctrl' => [
        'title' => 'Example fixture greeting',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'versioningWS' => true,
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'translationSource' => 'l10n_source',
    ],
    'columns' => [
        'sys_language_uid' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.language',
            'config' => [
                'type' => 'language',
            ],
        ],
        'l10n_parent' => [
            'displayCond' => 'FIELD:sys_language_uid:>:0',
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.l18n_parent',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'default' => 0,
                'items' => [
                    ['label' => '', 'value' => 0],
                ],
                'foreign_table' => 'tx_examplefixture_domain_model_greeting',
                'foreign_table_where' => 'AND {#tx_examplefixture_domain_model_greeting}.{#pid}=###CURRENT_PID###'
                    . ' AND {#tx_examplefixture_domain_model_greeting}.{#sys_language_uid} IN (-1,0)',
            ],
        ],
        'l10n_diffsource' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'title' => [
            'label' => 'Title',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'message' => [
            'label' => 'Message',
            'config' => [
                'type' => 'text',
                'cols' => 40,
                'rows' => 5,
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'sys_language_uid, l10n_parent, l10n_diffsource, title, message',
        ],
    ],
];

// Both supported core versions evaluate 'searchFields' and search nothing
// without it, so it is declared unconditionally. TYPO3 v14 removed the option
// (Breaking #106972) - there all fields of a suitable type are searchable by
// default and the option is migrated away at runtime with a deprecation - which
// is why the 2.x line of this extension guards this assignment and this branch
// does not. A guard here would be a condition that is true on every version the
// branch supports, which reads as a version difference and is none.
$tcaConfiguration['ctrl']['searchFields'] = 'title,message';

return $tcaConfiguration;
