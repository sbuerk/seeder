<?php

$EM_CONF['seeder'] = [
    'title' => 'TYPO3 Data Seeder',
    'description' => 'Seeds pages, records, files and site configurations into a TYPO3 installation from YAML definitions shipped by extensions.',
    'version' => '1.0.0',
    'category' => 'misc',
    'state' => 'alpha',
    'author' => 'Stefan Bürk',
    'author_email' => 'stefan@buerk.tech',
    'author_company' => '',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.5.99',
            'typo3' => '13.4.0-14.3.99',
            'core' => '13.4.0-14.3.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
