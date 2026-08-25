<?php

declare(strict_types=1);

namespace OliverThiele\OtMailcatcher\Tests\Unit\Check;

use OliverThiele\OtMailcatcher\Check\EmptySubjectCheck;
use OliverThiele\OtMailcatcher\Check\Severity;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * A mail without a subject reaches the recipient as an empty line in their list.
 */
final class EmptySubjectCheckTest extends UnitTestCase
{
    private EmptySubjectCheck $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new EmptySubjectCheck();
    }

    #[Test]
    public function aMailWithASubjectPasses(): void
    {
        self::assertSame([], $this->subject->check(CapturedMailFactory::create()));
    }

    #[Test]
    public function anEmptySubjectIsReported(): void
    {
        $results = $this->subject->check(CapturedMailFactory::create(['subject' => '']));

        self::assertCount(1, $results);
        self::assertSame('emptySubject', $results[0]->identifier);
        self::assertSame(Severity::ERROR, $results[0]->severity);
    }

    #[Test]
    public function aSubjectOfOnlyWhitespaceCountsAsEmpty(): void
    {
        // Trimmed on purpose: "   " renders exactly like no subject at all.
        $results = $this->subject->check(CapturedMailFactory::create(['subject' => "  \t \n "]));

        self::assertCount(1, $results);
    }
}
