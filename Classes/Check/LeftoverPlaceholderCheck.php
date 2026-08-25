<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Check;

use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;

/**
 * A marker that survived rendering means a typo in the template or a variable
 * that was never assigned.
 *
 * Both patterns are checked in both body parts. The Fluid pattern used to be
 * applied to the plain text only, on the assumption that inline CSS would
 * otherwise produce a finding on every HTML mail. Measured, that does not hold:
 * the pattern requires a lowercase letter directly after the brace and allows
 * nothing but word characters and dots before the closing one, so neither
 * `{ color: #fff }` nor `{margin:0}` matches. What does match inside an HTML
 * body — `{user.firstName}` — is an unrendered placeholder the recipient can
 * see, which is precisely what this rule exists to report.
 */
final class LeftoverPlaceholderCheck implements MailCheckInterface
{
    private const MARKER_PATTERN = '/###[A-Z0-9_]+###/';
    private const FLUID_VARIABLE_PATTERN = '/\{[a-z][a-zA-Z0-9_]*(\.[a-zA-Z0-9_]+)*\}/';

    public function check(CapturedMail $mail): array
    {
        $hasMarker = preg_match(self::MARKER_PATTERN, $mail->htmlBody) === 1
            || preg_match(self::MARKER_PATTERN, $mail->textBody) === 1;

        $hasFluidVariable = preg_match(self::FLUID_VARIABLE_PATTERN, $mail->textBody) === 1
            || preg_match(self::FLUID_VARIABLE_PATTERN, $mail->htmlBody) === 1;

        if (!$hasMarker && !$hasFluidVariable) {
            return [];
        }

        return [new CheckResult('leftoverPlaceholder', Severity::ERROR)];
    }
}
