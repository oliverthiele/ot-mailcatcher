<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Check;

use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;

/**
 * The Unicode replacement character in decoded content means the mail was
 * assembled with a wrong charset — invisible in the raw source, obvious to the
 * recipient.
 */
final class BrokenEncodingCheck implements MailCheckInterface
{
    private const REPLACEMENT_CHARACTER = "\u{FFFD}";

    public function check(CapturedMail $mail): array
    {
        $haystack = $mail->subject . $mail->textBody . $mail->htmlBody;

        if (!str_contains($haystack, self::REPLACEMENT_CHARACTER)) {
            return [];
        }

        return [new CheckResult('brokenEncoding', Severity::WARNING)];
    }
}
