<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Form permissions',
    'description' => 'Adds permission-aware access control to the EXT:form database storage.',
    'category' => 'be',
    'author' => 'Josua Vogel',
    'author_email' => 'j.vogel97@web.de',
    'state' => 'stable',
    'version' => '1.0.3',
    'constraints' => [
        'depends' => ['typo3' => '14.3.0-14.99.99'],
        'conflicts' => [],
        'suggests' => [],
    ],
];
