<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Check;

use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;

/**
 * A mail client has no base URL, so a relative href simply does not resolve.
 * Plain http:// links still work but get flagged or rewritten by many clients.
 */
final class InsecureLinkCheck implements MailCheckInterface
{
    public function check(CapturedMail $mail): array
    {
        $results = [];

        if (preg_match('/<a\s[^>]*href=["\'](?!https?:|mailto:|tel:|#|\{)[^"\']+["\']/i', $mail->htmlBody) === 1) {
            $results[] = new CheckResult('relativeLink', Severity::WARNING);
        }

        if (preg_match('/<a\s[^>]*href=["\']http:\/\//i', $mail->htmlBody) === 1
            || str_contains($mail->textBody, 'http://')
        ) {
            $results[] = new CheckResult('insecureLink', Severity::WARNING);
        }

        return $results;
    }
}
