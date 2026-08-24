<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Service;

use OliverThiele\OtMailcatcher\Mail\FileTransport;
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
     * @return array<string, mixed>
     */
    private static function readState(): array
    {
        $stateFilePath = self::getStateFilePath();
        if (!is_file($stateFilePath)) {
            return [];
        }

        $rawState = file_get_contents($stateFilePath);
        if ($rawState === false) {
            return [];
        }

        $decodedState = json_decode($rawState, true);

        return is_array($decodedState) ? $decodedState : [];
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

        return ($mailConfiguration['transport'] ?? null) === FileTransport::class;
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

    public static function setEnabled(bool $enabled): void
    {
        $storageDirectory = self::getStorageDirectory();
        if (!is_dir($storageDirectory)) {
            mkdir($storageDirectory, 0775, true);
        }

        $state = [
            'enabled' => $enabled,
            'changedAt' => date(\DATE_ATOM),
        ];

        file_put_contents(
            self::getStateFilePath(),
            json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"
        );
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
