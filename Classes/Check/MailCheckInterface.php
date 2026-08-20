<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Check;

use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;

/**
 * A single rule applied to a captured mail.
 *
 * Implementations are picked up automatically through the `ot_mailcatcher.check`
 * service tag, so a project can add its own rules without changing this package.
 */
interface MailCheckInterface
{
    /**
     * @return CheckResult[] Empty when the mail passes this rule.
     */
    public function check(CapturedMail $mail): array;
}
