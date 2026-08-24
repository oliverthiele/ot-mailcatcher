<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Mailcatcher',
    'description' => 'Capture outgoing mails as files instead of sending them, review them in the backend and check them for the usual configuration mistakes.',
    'category' => 'module',
    'author' => 'Oliver Thiele',
    'author_email' => 'mail@oliver-thiele.de',
    'state' => 'alpha',
    'version' => '0.1.4',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.3.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
