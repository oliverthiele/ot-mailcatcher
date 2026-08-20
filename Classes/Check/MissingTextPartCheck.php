<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Check;

use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;

/**
 * An HTML-only mail scores noticeably worse with spam filters and is unreadable
 * in clients that show plain text.
 */
final class MissingTextPartCheck implements MailCheckInterface
{
    public function check(CapturedMail $mail): array
    {
        if (!$mail->hasHtmlPart || $mail->hasTextPart) {
            return [];
        }

        return [new CheckResult('missingTextPart', Severity::WARNING)];
    }
}
