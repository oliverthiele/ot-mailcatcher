<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Check;

/**
 * One finding.
 *
 * The identifier is the machine-readable half — it is what the test API asserts
 * on and what stays stable across translations. The two labels are derived from
 * it by convention: `check.{identifier}.message` states the problem,
 * `check.{identifier}.hint` states what correct looks like.
 */
final class CheckResult
{
    /**
     * @param string[] $arguments Ordered placeholder values for the message label (%1$s, %2$s, ...)
     */
    public function __construct(
        public readonly string $identifier,
        public readonly Severity $severity,
        public readonly array $arguments = [],
    ) {}

    public function getMessageLabelKey(): string
    {
        return 'check.' . $this->identifier . '.message';
    }

    public function getHintLabelKey(): string
    {
        return 'check.' . $this->identifier . '.hint';
    }
}
