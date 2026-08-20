<?php

use OliverThiele\OtMailcatcher\Middleware\MailcatcherApiMiddleware;

return [
    'frontend' => [
        'oliverthiele/ot-mailcatcher/api' => [
            'target' => MailcatcherApiMiddleware::class,
            // Runs before the page resolver: the API has no page context and
            // must answer even when no site matches the request.
            'before' => [
                'typo3/cms-frontend/eid',
            ],
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
    ],
];
