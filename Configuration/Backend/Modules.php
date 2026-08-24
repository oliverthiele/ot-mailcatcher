<?php

use OliverThiele\OtMailcatcher\Controller\MailcatcherModuleController;

return [
    'system_mailcatcher' => [
        'parent' => 'system',
        'access' => 'admin',
        'workspaces' => 'live',
        'iconIdentifier' => 'ot-mailcatcher',
        'path' => '/module/system/mailcatcher',
        'labels' => 'LLL:EXT:ot_mailcatcher/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'OtMailcatcher',
        'controllerActions' => [
            MailcatcherModuleController::class => [
                'index',
                'show',
                'body',
                'attachment',
                'toggle',
                'delete',
                'confirm',
                'deleteAll',
                'resendAll',
            ],
        ],
    ],
];
