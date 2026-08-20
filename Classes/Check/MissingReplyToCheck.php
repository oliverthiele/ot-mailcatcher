<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Check;

use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;

/**
 * Without a Reply-To, hitting "Reply" answers the no-reply sender address
 * instead of the person who filled in the form.
 */
final class MissingReplyToCheck implements MailCheckInterface
{
    public function check(CapturedMail $mail): array
    {
        if ($mail->replyTo !== []) {
            return [];
        }

        return [new CheckResult('missingReplyTo', Severity::WARNING)];
    }
}
