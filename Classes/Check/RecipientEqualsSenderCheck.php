<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Check;

use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;

/**
 * Usually a finisher whose recipient and sender fields were filled with the
 * same value.
 */
final class RecipientEqualsSenderCheck implements MailCheckInterface
{
    public function check(CapturedMail $mail): array
    {
        $sender = MailAddressHelper::extractAddress($mail->from);
        if ($sender === '') {
            return [];
        }

        foreach ($mail->to as $recipient) {
            if (MailAddressHelper::extractAddress($recipient) === $sender) {
                return [new CheckResult('recipientEqualsSender', Severity::HINT)];
            }
        }

        return [];
    }
}
