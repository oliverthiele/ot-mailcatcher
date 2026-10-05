<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Check;

final class MailAddressHelper
{
    /**
     * Extracts the bare address from either "a@b.tld" or "Name <a@b.tld>".
     */
    public static function extractAddress(string $address): string
    {
        if (preg_match('/<([^>]+)>/', $address, $matches) === 1) {
            return strtolower(trim($matches[1]));
        }

        return strtolower(trim($address));
    }

    public static function extractDomain(string $address): string
    {
        $bareAddress = self::extractAddress($address);
        $atPosition = strrpos($bareAddress, '@');

        return $atPosition === false ? '' : substr($bareAddress, $atPosition + 1);
    }

    /**
     * Whether $domain is the installation's own domain or a subdomain of it, or
     * the other way round. noreply@mail.example.com and office@example.com
     * belong to the same site; an exact comparison reported one of them as a
     * visitor's address.
     */
    public static function belongsToDomain(string $domain, string $ownDomain): bool
    {
        if ($domain === '' || $ownDomain === '') {
            return false;
        }

        return $domain === $ownDomain
            || str_ends_with($domain, '.' . $ownDomain)
            || str_ends_with($ownDomain, '.' . $domain);
    }

    /**
     * The domain the installation sends as, taken from the global mail defaults.
     * Used as the reference for "is this our own address or the visitor's?".
     */
    public static function getDefaultSenderDomain(): string
    {
        $configurationVariables = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        if (!is_array($configurationVariables)) {
            return '';
        }

        $mailSettings = $configurationVariables['MAIL'] ?? null;
        if (!is_array($mailSettings)) {
            return '';
        }

        $defaultFromAddress = $mailSettings['defaultMailFromAddress'] ?? null;

        return is_string($defaultFromAddress) ? self::extractDomain($defaultFromAddress) : '';
    }
}
