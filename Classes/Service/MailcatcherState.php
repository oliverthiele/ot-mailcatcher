<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Service;

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
    private const ALLOW_ENVIRONMENT_VARIABLE = 'MAILCATCHER_ALLOWED';

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
        if (self::readAllowEnvironmentVariable() === '1') {
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
        $stateFilePath = self::getStateFilePath();
        if (!is_file($stateFilePath)) {
            return false;
        }

        $rawState = file_get_contents($stateFilePath);
        if ($rawState === false) {
            return false;
        }

        $decodedState = json_decode($rawState, true);
        if (!is_array($decodedState)) {
            return false;
        }

        return ($decodedState['enabled'] ?? false) === true;
    }

    /**
     * The effective state: switched on AND permitted in this environment.
     */
    public static function isActive(): bool
    {
        return self::isAllowed() && self::isEnabled();
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

    private static function readAllowEnvironmentVariable(): string
    {
        $value = getenv(self::ALLOW_ENVIRONMENT_VARIABLE);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $fallback = $_ENV[self::ALLOW_ENVIRONMENT_VARIABLE] ?? null;

        return is_scalar($fallback) ? (string)$fallback : '';
    }
}
