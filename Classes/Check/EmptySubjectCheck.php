<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Check;

use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;

final class EmptySubjectCheck implements MailCheckInterface
{
    public function check(CapturedMail $mail): array
    {
        if (trim($mail->subject) !== '') {
            return [];
        }

        return [new CheckResult('emptySubject', Severity::ERROR)];
    }
}
