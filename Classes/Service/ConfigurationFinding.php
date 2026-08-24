<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Service;

use OliverThiele\OtMailcatcher\Check\Severity;

/**
 * One finding about the extension's own configuration.
 *
 * Deliberately shaped like Check\CheckResult: the identifier is the stable,
 * machine-readable half, and the two labels are derived from it by convention —
 * `configuration.{identifier}.message` states the problem,
 * `configuration.{identifier}.hint` states what correct looks like.
 *
 * Unlike a CheckResult this describes the installation, not a captured mail, so
 * it is reported in the module header and the Reports module rather than on a
 * single message.
 */
final class ConfigurationFinding
{
    public function __construct(
        public readonly string $identifier,
        public readonly Severity $severity,
    ) {
    }

    public function getMessageLabelKey(): string
    {
        return 'configuration.' . $this->identifier . '.message';
    }

    public function getHintLabelKey(): string
    {
        return 'configuration.' . $this->identifier . '.hint';
    }
}
