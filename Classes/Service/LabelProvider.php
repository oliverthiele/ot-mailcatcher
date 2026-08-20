<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Service;

use TYPO3\CMS\Core\Authentication\AbstractUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Resolves this extension's labels for the current backend user.
 *
 * Exists so the LLL path and the narrowing of $GLOBALS['BE_USER'] live in one
 * place instead of being repeated in every listener.
 */
final class LabelProvider
{
    private const LANGUAGE_FILE = 'LLL:EXT:ot_mailcatcher/Resources/Private/Language/locallang_mod.xlf:';

    public function __construct(
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function get(string $key): string
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $this->languageServiceFactory
            ->createFromUserPreferences($backendUser instanceof AbstractUserAuthentication ? $backendUser : null)
            ->sL(self::LANGUAGE_FILE . $key);
    }
}
