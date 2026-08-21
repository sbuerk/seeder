<?php

$EM_CONF['data_factory'] = [
    'title' => 'TYPO3 Data Factory',
    'description' => 'Seeds pages, records, files and site configurations into a TYPO3 installation from YAML definitions shipped by extensions.',
    'version' => '1.0.0',
    'category' => 'misc',
    'state' => 'alpha',
    'author' => 'Stefan Bürk',
    'author_email' => 'stefan@buerk.tech',
    'author_company' => '',
    'constraints' => [
        'depends' => [
            'php' => '8.1.0-8.4.99',
            'typo3' => '12.4.22-13.4.99',
            'core' => '12.4.22-13.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
