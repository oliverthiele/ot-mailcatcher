<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Check;

use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;

/**
 * A marker that survived rendering means a typo in the template or a variable
 * that was never assigned.
 *
 * `###MARKER###` is checked in both parts. Fluid-style `{variable}` is checked
 * in the plain text part only — an HTML mail carries inline CSS, where braces
 * are ordinary syntax and would produce a false finding on every single mail.
 */
final class LeftoverPlaceholderCheck implements MailCheckInterface
{
    private const MARKER_PATTERN = '/###[A-Z0-9_]+###/';
    private const FLUID_VARIABLE_PATTERN = '/\{[a-z][a-zA-Z0-9_]*(\.[a-zA-Z0-9_]+)*\}/';

    public function check(CapturedMail $mail): array
    {
        $hasMarker = preg_match(self::MARKER_PATTERN, $mail->htmlBody) === 1
            || preg_match(self::MARKER_PATTERN, $mail->textBody) === 1;

        $hasFluidVariable = preg_match(self::FLUID_VARIABLE_PATTERN, $mail->textBody) === 1;

        if (!$hasMarker && !$hasFluidVariable) {
            return [];
        }

        return [new CheckResult('leftoverPlaceholder', Severity::ERROR)];
    }
}
