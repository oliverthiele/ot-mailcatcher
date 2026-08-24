<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Check;

use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;

/**
 * Applies every registered rule to a mail.
 *
 * The same runner serves the backend module and the test API — that is the
 * point of it: an editor reads the findings as sentences, CI asserts on the
 * identifiers, and both come from one implementation.
 */
class CheckRunner
{
    /**
     * @param iterable<MailCheckInterface> $checks
     */
    public function __construct(
        private readonly iterable $checks,
    ) {
    }

    /**
     * @return CheckResult[]
     */
    public function run(CapturedMail $mail): array
    {
        $results = [];
        foreach ($this->checks as $check) {
            foreach ($check->check($mail) as $result) {
                $results[] = $result;
            }
        }

        usort(
            $results,
            static fn(CheckResult $a, CheckResult $b): int => self::weigh($a->severity) <=> self::weigh($b->severity)
        );

        return $results;
    }

    /**
     * @param CheckResult[] $results
     */
    public function getHighestSeverity(array $results): ?Severity
    {
        $highest = null;
        foreach ($results as $result) {
            if ($highest === null || self::weigh($result->severity) < self::weigh($highest)) {
                $highest = $result->severity;
            }
        }

        return $highest;
    }

    private static function weigh(Severity $severity): int
    {
        return match ($severity) {
            Severity::ERROR => 0,
            Severity::WARNING => 1,
            Severity::HINT => 2,
        };
    }
}
