<?php

declare(strict_types=1);

use OliverThiele\OtMailcatcher\Mail\FileTransport;
use OliverThiele\OtMailcatcher\Mail\RefusingTransport;
use OliverThiele\OtMailcatcher\Service\MailcatcherState;

defined('TYPO3') or die();

// One of two wiring layers, and on its own not enough.
//
// This one runs after config/system/additional.php (Bootstrap:
// populateLocalConfiguration() vs. ExtLocalconfFactory::load()), so it wins over
// project code that rewrites the MAIL array, and it works without any project
// configuration at all.
//
// What it does NOT cover is a reduced bootstrap. The install tool's mail test
// calls BootService::getContainer() without loadExtLocalconfDatabaseAndExtTables(),
// so no extension configuration is loaded and this file never runs — that mail
// would be delivered for real. additional.php is read by every bootstrap and
// covers exactly that gap, which is why the README still asks for it.
//
// The condition is evaluated on every request: ext_localconf.php is cached as
// concatenated PHP, but that PHP is executed each time.
// Recorded before the assignment below, while the two layers can still be told
// apart. See MailcatcherState::wasWiredByProjectConfiguration().
if (MailcatcherState::isWired()) {
    MailcatcherState::markWiredByProjectConfiguration();
}

if (MailcatcherState::isActive()) {
    $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = FileTransport::class;
} elseif (MailcatcherState::isEnabled()) {
    // Switched on, but not permitted in this context — refuse rather than
    // deliver. See RefusingTransport for why this is the safe direction.
    $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = RefusingTransport::class;
}
