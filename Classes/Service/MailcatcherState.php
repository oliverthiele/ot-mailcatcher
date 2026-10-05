<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Service;

use OliverThiele\OtMailcatcher\Mail\FileTransport;
use OliverThiele\OtMailcatcher\Mail\RefusingTransport;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Reads and writes the on/off state of the mail catcher.
 *
 * Deliberately static and free of dependency injection: the state is read from
 * `config/system/additional.php`, which runs while the container is still being
 * built. The state lives in a file below var/ rather than in the extension
 * configuration, because settings.php is version-controlled in most projects and
 * TYPO3 rewrites its EXTENSIONS block on its own.
 */
final class MailcatcherState
{
    private const DIRECTORY_NAME = 'mailcatcher';
    private const STATE_FILE_NAME = 'state.json';
    public const ALLOW_ENVIRONMENT_VARIABLE = 'MAILCATCHER_ALLOWED';

    /**
     * Whether config/system/additional.php had already pointed the transport at
     * the catcher by the time ext_localconf.php ran.
     *
     * The two wiring layers cover different bootstraps: ext_localconf.php is
     * skipped by reduced bootstraps such as the install tool's mail test, which
     * builds a container without loading extension configuration, while
     * additional.php is read by every bootstrap. Once ext_localconf.php has
     * assigned the transport the difference is invisible — so it is recorded
     * here, before the assignment.
     */
    private static bool $wiredByProjectConfiguration = false;

    /**
     * Directory holding the captured .eml files and the state file.
     */
    public static function getStorageDirectory(): string
    {
        return rtrim(Environment::getVarPath(), '/') . '/' . self::DIRECTORY_NAME;
    }

    public static function getStateFilePath(): string
    {
        return self::getStorageDirectory() . '/' . self::STATE_FILE_NAME;
    }

    /**
     * Whether the catcher may be switched on at all in this environment.
     *
     * Production is locked out unless explicitly allowed, because a forgotten
     * catcher on a live system silently stops every outgoing mail.
     */
    public static function isAllowed(): bool
    {
        if (self::readEnvironmentVariable(self::ALLOW_ENVIRONMENT_VARIABLE) === '1') {
            return true;
        }

        return !Environment::getContext()->isProduction();
    }

    /**
     * Whether the editor switched the catcher on. Says nothing about whether it
     * is allowed here — use isActive() for the effective state.
     */
    public static function isEnabled(): bool
    {
        return (self::readState()['enabled'] ?? false) === true;
    }

    /**
     * A state file that exists but cannot be read counts as switched on. The
     * other reading would deliver mail for real while somebody believes it is
     * captured; this one at worst captures or refuses a mail that could have
     * gone out.
     *
     * @return array<string, mixed>
     */
    private static function readState(): array
    {
        $stateFilePath = self::getStateFilePath();
        if (!is_file($stateFilePath)) {
            return [];
        }

        $rawState = @file_get_contents($stateFilePath);
        $decodedState = is_string($rawState) ? json_decode($rawState, true) : null;
        if (!is_array($decodedState) || !is_bool($decodedState['enabled'] ?? null)) {
            return ['enabled' => true];
        }

        return $decodedState;
    }

    /**
     * The effective state: switched on AND permitted in this environment.
     */
    public static function isActive(): bool
    {
        return self::isAllowed() && self::isEnabled();
    }

    /**
     * Whether the mail transport actually points at the catcher.
     *
     * This is the only honest answer to "will an outgoing mail be captured?".
     * isActive() merely reports the switch; the capturing itself is wired up in
     * config/system/additional.php, and that line is easy to forget. Without
     * this check the backend would claim no mail is being sent while every mail
     * goes out as usual — see ConfigurationValidator.
     */
    public static function markWiredByProjectConfiguration(): void
    {
        self::$wiredByProjectConfiguration = true;
    }

    public static function wasWiredByProjectConfiguration(): bool
    {
        return self::$wiredByProjectConfiguration;
    }

    public static function isWired(): bool
    {
        $configurationVariables = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        if (!is_array($configurationVariables)) {
            return false;
        }

        $mailConfiguration = $configurationVariables['MAIL'] ?? null;
        if (!is_array($mailConfiguration)) {
            return false;
        }

        // TransportFactory ignores the transport class as soon as a DSN or a
        // spool type is set — see wireMailTransport(). Only all three together
        // mean that a mail actually reaches the catcher.
        return ($mailConfiguration['transport'] ?? null) === FileTransport::class
            && empty($mailConfiguration['dsn'])
            && empty($mailConfiguration['transport_spool_type']);
    }

    /**
     * Points the mail configuration at the catcher, or at RefusingTransport
     * where the catcher is switched on but not permitted. Leaves it alone while
     * the catcher is off.
     *
     * Called from ext_localconf.php and from the block the README asks for in
     * config/system/additional.php, so both layers do exactly the same.
     *
     * Setting `transport` alone is not enough. TransportFactory::get() resolves
     * a non-empty `transport_spool_type` to a spool before it looks at the
     * transport, and its switch contains `case !empty($mailSettings['dsn'])`:
     * PHP compares loosely, every class name equals true, so a configured DSN
     * wins over any class name and the mail is sent for real. Both are cleared.
     */
    public static function wireMailTransport(): void
    {
        if (self::isActive()) {
            self::assignTransport(FileTransport::class);
        } elseif (self::isEnabled()) {
            // Switched on, but not permitted in this context — refuse rather than
            // deliver. See RefusingTransport for why this is the safe direction.
            self::assignTransport(RefusingTransport::class);
        }
    }

    private static function assignTransport(string $transportClass): void
    {
        $configurationVariables = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        $configurationVariables = is_array($configurationVariables) ? $configurationVariables : [];
        $mailConfiguration = $configurationVariables['MAIL'] ?? [];
        $mailConfiguration = is_array($mailConfiguration) ? $mailConfiguration : [];

        $mailConfiguration['transport'] = $transportClass;
        $mailConfiguration['dsn'] = '';
        $mailConfiguration['transport_spool_type'] = '';

        $configurationVariables['MAIL'] = $mailConfiguration;
        $GLOBALS['TYPO3_CONF_VARS'] = $configurationVariables;
    }

    /**
     * When the catcher was switched on, or null while it is off.
     *
     * The backend shows this because a catcher meant for a short incident window
     * reads differently after three days than after ten minutes — and the people
     * who notice the missing mail are website visitors, who never see the banner.
     */
    public static function getEnabledSince(): ?\DateTimeImmutable
    {
        if (!self::isEnabled()) {
            return null;
        }

        $state = self::readState();
        $changedAt = $state['changedAt'] ?? null;
        if (!is_string($changedAt)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($changedAt);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Written to a temporary file and renamed into place, so a request reading
     * the state at the same moment sees the old or the new file, never a
     * truncated one. Failures throw instead of leaving the switch where it was
     * while the module reports it changed.
     */
    public static function setEnabled(bool $enabled): void
    {
        $storageDirectory = self::getStorageDirectory();
        if (!is_dir($storageDirectory) && !@mkdir($storageDirectory, 0775, true) && !is_dir($storageDirectory)) {
            throw new \RuntimeException(sprintf('Could not create the mailcatcher directory "%s".', $storageDirectory), 1790900001);
        }

        $state = [
            'enabled' => $enabled,
            'changedAt' => date(\DATE_ATOM),
        ];

        $temporaryPath = self::getStateFilePath() . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $written = @file_put_contents(
            $temporaryPath,
            json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"
        );
        if ($written === false || !@rename($temporaryPath, self::getStateFilePath())) {
            @unlink($temporaryPath);
            throw new \RuntimeException(sprintf('Could not write the mailcatcher state to "%s".', self::getStateFilePath()), 1790900002);
        }
    }

    /**
     * Reads one of this extension's environment variables, falling back to
     * $_ENV for setups where the variable never reaches getenv().
     *
     * Public because the API middleware and the configuration validator read
     * their variables the same way, and one implementation is easier to keep
     * honest than three.
     */
    public static function readEnvironmentVariable(string $name): string
    {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $fallback = $_ENV[$name] ?? null;

        return is_scalar($fallback) ? (string)$fallback : '';
    }
}
