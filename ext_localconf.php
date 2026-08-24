<?php

declare(strict_types=1);

use OliverThiele\OtMailcatcher\Mail\FileTransport;
use OliverThiele\OtMailcatcher\Mail\RefusingTransport;
use OliverThiele\OtMailcatcher\Service\MailcatcherState;

defined('TYPO3') or die();

// Wired here rather than in the project's config/system/additional.php, which is
// where this used to live. ext_localconf.php runs after additional.php (see
// Bootstrap::populateLocalConfiguration() vs. ExtLocalconfFactory::load()), so
// this wins over any project code that rewrites the MAIL array — and, more
// importantly, it cannot be forgotten. A missing project block used to mean the
// backend reported that no mail was being sent while every mail went out.
//
// The condition is evaluated on every request: ext_localconf.php is cached as
// concatenated PHP, but that PHP is executed each time.
if (MailcatcherState::isActive()) {
    $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = FileTransport::class;
} elseif (MailcatcherState::isEnabled()) {
    // Switched on, but not permitted in this context — refuse rather than
    // deliver. See RefusingTransport for why this is the safe direction.
    $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = RefusingTransport::class;
}
