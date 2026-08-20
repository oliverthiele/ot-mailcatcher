<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Check;

use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;

/**
 * `t3://` links resolve in the frontend but not in a mail template that was
 * rendered without the right context — the recipient then sees the raw URI.
 * A TYPO3-specific failure no general-purpose mail tool can detect.
 */
final class UnresolvedTypo3LinkCheck implements MailCheckInterface
{
    public function check(CapturedMail $mail): array
    {
        if (!str_contains($mail->htmlBody, 't3://') && !str_contains($mail->textBody, 't3://')) {
            return [];
        }

        return [new CheckResult('unresolvedTypo3Link', Severity::ERROR)];
    }
}
