<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Check;

use OliverThiele\OtMailcatcher\Check\CheckResult;
use OliverThiele\OtMailcatcher\Check\CheckRunner;
use OliverThiele\OtMailcatcher\Check\MailCheckInterface;
use OliverThiele\OtMailcatcher\Check\Severity;
use OliverThiele\OtMailcatcher\Domain\Dto\CapturedMail;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The aggregator behind the finding count in the module and the API.
 *
 * Ordering is the part worth pinning: an editor reads the first finding, so an
 * error must never sit below a hint.
 */
final class CheckRunnerTest extends UnitTestCase
{
    private function runner(CheckResult ...$results): CheckRunner
    {
        $check = new class ($results) implements MailCheckInterface {
            /** @param CheckResult[] $results */
            public function __construct(private readonly array $results)
            {
            }

            public function check(CapturedMail $mail): array
            {
                return $this->results;
            }
        };

        return new CheckRunner([$check]);
    }

    #[Test]
    public function aMailWithoutFindingsReturnsAnEmptyList(): void
    {
        self::assertSame([], $this->runner()->run(CapturedMailFactory::create()));
    }

    #[Test]
    public function findingsAreSortedBySeverity(): void
    {
        $runner = $this->runner(
            new CheckResult('third', Severity::HINT),
            new CheckResult('first', Severity::ERROR),
            new CheckResult('second', Severity::WARNING),
        );

        $results = $runner->run(CapturedMailFactory::create());

        self::assertSame(
            ['first', 'second', 'third'],
            array_map(static fn(CheckResult $r) => $r->identifier, $results)
        );
    }

    #[Test]
    public function theHighestSeverityIsTheMostSevereOne(): void
    {
        $runner = $this->runner();

        self::assertSame(Severity::ERROR, $runner->getHighestSeverity([
            new CheckResult('a', Severity::HINT),
            new CheckResult('b', Severity::ERROR),
            new CheckResult('c', Severity::WARNING),
        ]));
    }

    #[Test]
    public function withoutFindingsThereIsNoHighestSeverity(): void
    {
        self::assertNull($this->runner()->getHighestSeverity([]));
    }
}
