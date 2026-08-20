<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Check;

use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;

/**
 * The classic form-finisher mistake: the mail claims to come from the visitor's
 * own address, but it is sent by our server. SPF and DMARC reject exactly that,
 * so the mail is silently filed as spam or dropped.
 */
final class SenderIsWebsiteVisitorCheck implements MailCheckInterface
{
    public function check(CapturedMail $mail): array
    {
        $ownDomain = MailAddressHelper::getDefaultSenderDomain();
        $senderDomain = MailAddressHelper::extractDomain($mail->from);

        if ($ownDomain === '' || $senderDomain === '' || $senderDomain === $ownDomain) {
            return [];
        }

        return [
            new CheckResult(
                'senderIsWebsiteVisitor',
                Severity::ERROR,
                [$senderDomain, $ownDomain]
            ),
        ];
    }
}
