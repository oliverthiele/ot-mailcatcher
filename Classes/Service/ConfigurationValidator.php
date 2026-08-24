<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Service;

use OliverThiele\OtMailcatcher\Check\Severity;
use OliverThiele\OtMailcatcher\Middleware\MailcatcherApiMiddleware;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Validates that the extension is wired up and configured the way it claims.
 *
 * Exists because the switch in the backend module and the actual capturing are
 * two different things: the module writes a state file, but no mail is captured
 * until config/system/additional.php points the mail transport at FileTransport.
 * Forget that line and the backend reports "no mail is being sent" while every
 * mail goes out — the one wrong answer that makes someone deliberately send test
 * mails to real addresses.
 *
 * The environment findings are a second, milder class: they never change what
 * happens to a mail right now, but each one is a way a production system can be
 * talked into swallowing its mail later.
 */
final class ConfigurationValidator
{
    /**
     * Below this length a token is worth guessing. `openssl rand -hex 32`
     * produces 64 characters and is what the README recommends.
     */
    private const MINIMUM_TOKEN_LENGTH = 32;

    public function getStatus(): MailcatcherStatus
    {
        // Checked first: the switch is on, so reporting "inactive" here would
        // hide that somebody expects mail to be captured. It is not, and mail is
        // going out.
        if (MailcatcherState::isEnabled() && !MailcatcherState::isAllowed()) {
            return MailcatcherStatus::LOCKED;
        }

        if (MailcatcherState::isActive()) {
            return MailcatcherState::isWired()
                ? MailcatcherStatus::ACTIVE
                : MailcatcherStatus::NOT_TAKING_EFFECT;
        }

        return MailcatcherState::isWired()
            ? MailcatcherStatus::STRAY_TRANSPORT
            : MailcatcherStatus::INACTIVE;
    }

    /**
     * Findings about the environment variables. Independent of the status: a
     * production system that can be unlocked is worth reporting whether or not
     * the catcher happens to be running.
     *
     * @return ConfigurationFinding[]
     */
    public function getEnvironmentFindings(): array
    {
        $findings = [];
        $context = Environment::getContext();
        $isProduction = $context->isProduction();

        $allowValue = MailcatcherState::readEnvironmentVariable(MailcatcherState::ALLOW_ENVIRONMENT_VARIABLE);
        if ($isProduction && $allowValue === '1') {
            $findings[] = new ConfigurationFinding('productionUnlocked', Severity::WARNING);
        }

        // '0' is a deliberate "off" and needs no comment. Any other non-empty
        // value is a spelling of "yes" that does nothing — the check compares
        // against the literal '1'.
        if ($allowValue !== '' && $allowValue !== '1' && $allowValue !== '0') {
            $findings[] = new ConfigurationFinding('allowValueIgnored', Severity::HINT);
        }

        // Unlocked by context alone. Every process running in a Production
        // context then sends for real — and the command line defaults to
        // Production even where the web server sets a development context, so a
        // bulk send from a scheduler task reaches real recipients while the
        // backend reports that nothing is being sent.
        if (MailcatcherState::isEnabled() && !$isProduction && $allowValue !== '1') {
            $findings[] = new ConfigurationFinding('allowedMissing', Severity::WARNING);
        }

        $apiToken = MailcatcherState::readEnvironmentVariable(
            MailcatcherApiMiddleware::TOKEN_ENVIRONMENT_VARIABLE
        );
        if ($apiToken !== '') {
            if ($isProduction) {
                $findings[] = new ConfigurationFinding('apiTokenInProduction', Severity::WARNING);
            }
            // Not on a developer machine: a throwaway token is the norm there and
            // nothing reaches it from outside. A warning that stands permanently
            // during normal work is one people learn to look past — including the
            // ones next to it that do matter.
            if (!$context->isDevelopment() && strlen($apiToken) < self::MINIMUM_TOKEN_LENGTH) {
                $findings[] = new ConfigurationFinding('apiTokenTooShort', Severity::WARNING);
            }
        }

        return $findings;
    }
}
